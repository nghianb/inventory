<?php

use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Hai Phiếu xuất tạo cùng lúc trong hai tiến trình, mỗi tiến trình một kết nối Postgres. Dữ liệu
 * phải commit thật nên không dùng RefreshDatabase; DB được dựng lại trước và sau mỗi test.
 */

beforeEach(function () {
    $this->artisan('migrate:fresh');
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->channel = app(SalesChannelDirectory::class)->create($admin, new SalesChannelDraft('Zalo'));
    $this->product = app(ProductCatalog::class)->create($admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', sensitive: false, dedupeKey: true)],
    ));

    $intake = app(BatchIntake::class);
    $intake->confirm($admin, $intake->submit($admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($admin, 'Kinguin'),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->product, 10_000, implode("\n", array_map(fn (int $i): string => sprintf('CODE%04d', $i), range(1, 40))))],
    )));
});

afterEach(function () {
    $this->artisan('migrate:fresh');
});

/**
 * Tạo các Phiếu xuất đồng thời, mỗi phiếu trong một tiến trình con.
 *
 * @param  list<DispatchDraft>  $drafts
 * @return list<string> 'ok' hoặc lỗi của từng tiến trình
 */
function dispatchInParallel(User $actor, array $drafts): array
{
    // Tiến trình con không được dùng chung socket DB của tiến trình cha.
    DB::disconnect();
    $children = [];

    foreach ($drafts as $draft) {
        $resultFile = (string) tempnam(sys_get_temp_dir(), 'dispatch-race');
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                app(ManualDispatch::class)->create($actor, $draft);
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

it('hai Phiếu xuất tạo cùng lúc cho cùng Sản phẩm không bao giờ chọn trùng Slot', function () {
    // 40 Slot, mỗi phiếu cần 20: bỏ qua Slot đang bị khoá thì mỗi bên luôn còn đủ phần của mình.
    $draft = new DispatchDraft($this->channel, null, [new DispatchLineDraft($this->product, 20)]);

    expect(dispatchInParallel($this->seller, [$draft, $draft]))->toBe(['ok', 'ok']);

    expect(Dispatch::count())->toBe(2)
        ->and(Dispatch::pluck('external_ref')->sort()->values()->all())->toBe(['PX-'.now()->format('Ymd').'-0001', 'PX-'.now()->format('Ymd').'-0002'])
        ->and(Delivery::count())->toBe(40)
        ->and(Delivery::distinct()->count('slot_id'))->toBe(40)
        ->and(Slot::where('status', SlotStatus::Delivered)->count())->toBe(40)
        ->and(StockLedgerEntry::where('to_status', SlotStatus::Delivered->value)->distinct()->count('slot_id'))->toBe(40);
});

it('ba Phiếu xuất tranh nhau hàng không đủ: phiếu thất bại không giao gì, không Slot nào bị giao hai lần', function () {
    $draft = new DispatchDraft($this->channel, null, [new DispatchLineDraft($this->product, 15)]);

    $results = dispatchInParallel($this->seller, [$draft, $draft, $draft]);
    $succeeded = count(array_filter($results, fn (string $result): bool => $result === 'ok'));

    expect($succeeded)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)
        ->and(array_values(array_filter($results, fn (string $result): bool => $result !== 'ok')))
        ->each->toStartWith('App\Inventory\Dispatch\OutOfStock: Không đủ hàng, không giao gì')
        ->and(Dispatch::count())->toBe($succeeded)
        ->and(Delivery::count())->toBe(15 * $succeeded)
        ->and(Delivery::distinct()->count('slot_id'))->toBe(15 * $succeeded)
        ->and(Slot::where('status', SlotStatus::InStock)->count())->toBe(40 - 15 * $succeeded);
});
