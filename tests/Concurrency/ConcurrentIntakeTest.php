<?php

use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ExpiryRule;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Hai Lô nhập xác nhận cùng lúc trong hai tiến trình, mỗi tiến trình một kết nối Postgres.
 * Không dùng RefreshDatabase: dữ liệu phải commit thật để tiến trình kia thấy, nên DB được
 * dựng lại trước và sau mỗi test.
 */

beforeEach(function () {
    $this->artisan('migrate:fresh');
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::QuanTri);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->intake = app(BatchIntake::class);
});

afterEach(function () {
    $this->artisan('migrate:fresh');
});

function concurrentProduct(ProductType $type, string $code): Product
{
    return app(ProductCatalog::class)->create(test()->admin, new ProductDraft(
        type: $type,
        name: $code,
        code: $code,
        fields: $type === ProductType::OneTimeCode
            ? [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)]
            : [new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true), new ContentFieldDraft('password', 'Mật khẩu')],
    ));
}

function concurrentBatch(BatchLineDraft $line, string $receivedOn = '2026-09-15'): Batch
{
    return test()->intake->submit(test()->admin, new BatchDraft(test()->supplier, CarbonImmutable::parse($receivedOn), [$line]));
}

/**
 * Xác nhận các Lô nhập đồng thời, mỗi Lô nhập trong một tiến trình con.
 *
 * @param  list<Batch>  $batches
 * @return list<string> 'ok' hoặc lỗi của từng tiến trình
 */
function confirmInParallel(User $actor, array $batches): array
{
    // Tiến trình con không được dùng chung socket DB của tiến trình cha.
    DB::disconnect();
    $children = [];

    foreach ($batches as $batch) {
        $resultFile = (string) tempnam(sys_get_temp_dir(), 'intake-race');
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                app(BatchIntake::class)->confirm($actor, $batch, skipStockDuplicates: true);
                file_put_contents($resultFile, 'ok');
            } catch (Throwable $exception) {
                file_put_contents($resultFile, $exception::class.': '.$exception->getMessage());
            }

            // Thoát ngay, không chạy shutdown của PHPUnit trong tiến trình con.
            posix_kill(getmypid(), SIGKILL);
        }

        $children[$pid] = $resultFile;
    }

    foreach (array_keys($children) as $pid) {
        pcntl_waitpid($pid, $status);
    }

    return array_values(array_map(fn (string $file): string => tap((string) file_get_contents($file), fn () => unlink($file)), $children));
}

it('hai Lô nhập xác nhận song song cùng Mã dùng một lần không chèn trùng Khoá chống trùng', function () {
    $codes = implode("\n", array_map(fn (int $i): string => sprintf('CODE-%05d', $i), range(1, 3_000)));
    $batches = [
        concurrentBatch(new BatchLineDraft(concurrentProduct(ProductType::OneTimeCode, 'STEAM-A'), 1_000, $codes)),
        concurrentBatch(new BatchLineDraft(concurrentProduct(ProductType::OneTimeCode, 'STEAM-B'), 2_000, $codes)),
    ];

    expect(confirmInParallel($this->admin, $batches))->toBe(['ok', 'ok']);

    $lines = BatchLine::query()->orderBy('id')->get();

    expect(StockUnit::count())->toBe(3_000)
        ->and(DB::table('stock_units')->distinct()->count('dedupe_hash'))->toBe(3_000)
        ->and($lines->sum('valid_count'))->toBe(3_000)
        ->and($lines->sum('stock_duplicate_count'))->toBe(3_000)
        ->and(StockLedgerEntry::count())->toBe(6_000);
});

it('hai Lô nhập song song cùng nhập lại một Tài khoản hết hạn thì chỉ một Đơn vị hàng mới chiếm khoá', function () {
    $netflix = concurrentProduct(ProductType::Account, 'NETFLIX-1M');
    $this->intake->confirm($this->admin, concurrentBatch(
        new BatchLineDraft($netflix, 100_000, "old@shop.test\tpw", expiry: ExpiryRule::on(CarbonImmutable::parse('2026-09-20'))),
    ));

    $this->travelTo(CarbonImmutable::parse('2026-09-21 10:00'));

    $batches = [
        concurrentBatch(new BatchLineDraft($netflix, 120_000, "old@shop.test\tpw-a"), '2026-09-21'),
        concurrentBatch(new BatchLineDraft(concurrentProduct(ProductType::Account, 'NETFLIX-3M'), 300_000, "old@shop.test\tpw-b"), '2026-09-21'),
    ];

    expect(BatchLine::query()->whereIn('batch_id', array_map(fn (Batch $batch) => $batch->id, $batches))->sum('renewal_count'))->toBe(2)
        ->and(confirmInParallel($this->admin, $batches))->toBe(['ok', 'ok']);

    $lines = BatchLine::query()->whereIn('batch_id', array_map(fn (Batch $batch) => $batch->id, $batches))->get();

    expect(StockUnit::count())->toBe(2)
        ->and(StockUnit::where('holds_dedupe_key', true)->count())->toBe(1)
        ->and($lines->sum('renewal_count'))->toBe(1)
        ->and($lines->sum('stock_duplicate_count'))->toBe(1);
});
