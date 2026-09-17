<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\ExpiryRule;
use App\Inventory\Intake\ImportReversal;
use App\Inventory\Intake\InvalidBatch;
use App\Inventory\Intake\ReversalPlan;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->intake = app(BatchIntake::class);
    $this->reversal = app(ImportReversal::class);
    $this->admin = staffMember(Role::Owner);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');

    $this->codes = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
    );
    $this->accounts = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 2,
    );
});

/**
 * Nhập và xác nhận một Lô nhập, mỗi phần tử một Dòng nhập [Sản phẩm, nội dung dán].
 *
 * @param  list<array{Product, string}>  $lines
 */
function confirmedReversalBatch(array $lines): Batch
{
    $intake = app(BatchIntake::class);
    $batch = $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: array_map(fn (array $line): BatchLineDraft => new BatchLineDraft($line[0], 100_000, $line[1]), $lines),
    ));

    return $intake->confirm(test()->admin, $batch)->load('lines');
}

it('Huỷ nhập cả Lô nhập rồi nhập lại đúng các mã đó thành công', function () {
    $batch = confirmedReversalBatch([[$this->codes, "AAAA-BBBB\nCCCC-DDDD"], [$this->accounts, "a@shop.test\tpw"]]);

    $done = $this->reversal->reverse($this->admin, $batch);

    expect($done)->toEqual(new ReversalPlan(reversedUnits: 2 + 1, reversedSlots: 2 + 2, keptUnits: 0))
        ->and(StockUnit::pluck('status')->unique()->all())->toBe([StockUnitStatus::Reversed])
        ->and(StockUnit::pluck('holds_dedupe_key')->unique()->all())->toBe([false])
        ->and(Slot::pluck('status')->unique()->all())->toBe([SlotStatus::Reversed])
        ->and(Product::withCount('inStockSlots')->find($this->codes->id)->in_stock_slots_count)->toBe(0)
        ->and(Product::withCount('inStockSlots')->find($this->accounts->id)->in_stock_slots_count)->toBe(0);

    $again = confirmedReversalBatch([[$this->codes, "aaaabbbb\nCCCC-DDDD"], [$this->accounts, "a@shop.test\tpw"]]);

    expect($again->lines->sum('valid_count'))->toBe(3)
        ->and($again->lines->sum('stock_duplicate_count'))->toBe(0)
        ->and($again->lines->sum('renewal_count'))->toBe(0)
        ->and(StockUnit::count())->toBe(6)
        ->and(StockUnit::where('status', StockUnitStatus::Active)->whereNull('renews_stock_unit_id')->count())->toBe(3)
        ->and(Product::withCount('inStockSlots')->find($this->codes->id)->in_stock_slots_count)->toBe(2);
});

it('Huỷ nhập theo Dòng nhập chỉ đụng tới Dòng nhập đó', function () {
    $batch = confirmedReversalBatch([[$this->codes, 'AAAA-BBBB'], [$this->accounts, "a@shop.test\tpw"]]);
    $accountLine = $batch->lines->firstOrFail(fn ($line) => $line->product_id === $this->accounts->id);

    $this->reversal->reverse($this->admin, $accountLine);

    expect(StockUnit::where('product_id', $this->codes->id)->sole()->status)->toBe(StockUnitStatus::Active)
        ->and(StockUnit::where('product_id', $this->accounts->id)->sole()->status)->toBe(StockUnitStatus::Reversed)
        ->and($accountLine->fresh()->reversed_count)->toBe(1)
        ->and($batch->lines->firstOrFail(fn ($line) => $line->product_id === $this->codes->id)->fresh()->reversed_count)->toBe(0);
});

it('Slot đã giữ hoặc đã giao không bị Huỷ nhập và mã của chúng vẫn trùng trong kho', function () {
    $batch = confirmedReversalBatch([
        [$this->codes, "AAAA-BBBB\nCCCC-DDDD\nEEEE-FFFF"],
        [$this->accounts, "sold@shop.test\tpw\nfresh@shop.test\tpw"],
    ]);
    // Chưa có Giữ hàng và Giao hàng trong module: đặt trạng thái trực tiếp.
    $reservedCode = StockUnit::where('product_id', $this->codes->id)->orderBy('id')->firstOrFail();
    Slot::where('stock_unit_id', $reservedCode->id)->update(['status' => SlotStatus::Reserved]);
    $soldAccount = StockUnit::where('content->username', 'sold@shop.test')->sole();
    Slot::whereKey($soldAccount->slots->first()->id)->update(['status' => SlotStatus::Delivered]);

    $plan = $this->reversal->plan($this->admin, $batch);

    expect($plan)->toEqual(new ReversalPlan(reversedUnits: 3, reversedSlots: 4, keptUnits: 2))
        ->and($this->reversal->reverse($this->admin, $batch))->toEqual($plan);

    expect($reservedCode->fresh())->status->toBe(StockUnitStatus::Active)->holds_dedupe_key->toBeTrue()
        ->and($reservedCode->slots()->pluck('status')->all())->toBe([SlotStatus::Reserved])
        ->and($soldAccount->fresh())->status->toBe(StockUnitStatus::Active)->holds_dedupe_key->toBeTrue()
        ->and($soldAccount->slots()->pluck('status')->all())->toBe([SlotStatus::Delivered, SlotStatus::InStock])
        ->and(StockUnit::where('content->username', 'fresh@shop.test')->sole()->status)->toBe(StockUnitStatus::Reversed)
        ->and($this->reversal->plan($this->admin, $batch))->toEqual(new ReversalPlan(reversedUnits: 0, reversedSlots: 0, keptUnits: 2));

    $again = $this->intake->submit($this->admin, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-16'),
        lines: [
            new BatchLineDraft($this->codes, 1, "AAAA-BBBB\nCCCC-DDDD\nEEEE-FFFF"),
            new BatchLineDraft($this->accounts, 1, "sold@shop.test\tpw\nfresh@shop.test\tpw"),
        ],
    ));
    $lines = $this->intake->preview($this->admin, $again)->lines;

    expect([$lines[0]->validCount, $lines[0]->stockDuplicateCount, $lines[1]->validCount, $lines[1]->stockDuplicateCount])
        ->toBe([2, 1, 1, 1]);
});

it('Huỷ nhập ghi Sổ biến động kho cho mỗi Đơn vị hàng và Slot, giữ nguyên bản ghi', function () {
    $batch = confirmedReversalBatch([[$this->accounts, "a@shop.test\tpw"]]);
    $unit = StockUnit::sole();
    $before = StockLedgerEntry::max('id');

    $this->reversal->reverse($this->admin, $batch, 'Nhà cung cấp gửi nhầm file');

    $rows = StockLedgerEntry::where('id', '>', $before)->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([$this->admin->id])
        ->and($rows->pluck('reason')->unique()->all())->toBe(["Huỷ nhập Lô nhập #{$batch->id}: Nhà cung cấp gửi nhầm file"])
        ->and($rows->map(fn (StockLedgerEntry $row) => [$row->slot_id, $row->from_status, $row->to_status])->all())
        ->toEqualCanonicalizing([
            [null, 'active', 'reversed'],
            [$unit->slots[0]->id, 'in-stock', 'reversed'],
            [$unit->slots[1]->id, 'in-stock', 'reversed'],
        ])
        ->and(StockUnit::count())->toBe(1)
        ->and(Slot::count())->toBe(2)
        ->and($unit->fresh()->batch_line_id)->toBe($batch->lines[0]->id);
});

it('Huỷ nhập Tài khoản nhập lại thì Đơn vị hàng cũ chiếm lại Khoá chống trùng, nhập lại vẫn liên kết với cái cũ', function () {
    confirmedReversalBatch([[$this->accounts, "a@shop.test\tpw"]]);
    $old = StockUnit::sole();
    StockUnit::whereKey($old->id)->update(['status' => StockUnitStatus::Voided]);

    $renewal = confirmedReversalBatch([[$this->accounts, "a@shop.test\tpw-moi"]]);

    expect($old->fresh()->holds_dedupe_key)->toBeFalse();

    $this->reversal->reverse($this->admin, $renewal->lines[0]);

    expect($old->fresh()->holds_dedupe_key)->toBeTrue();

    $again = confirmedReversalBatch([[$this->accounts, "a@shop.test\tpw-moi"]]);

    expect($again->lines[0]->renewal_count)->toBe(1)
        ->and(StockUnit::where('batch_line_id', $again->lines[0]->id)->sole()->renews_stock_unit_id)->toBe($old->id);
});

it('Đơn vị hàng cũ đã bị Huỷ nhập không lấy lại Khoá chống trùng khi Tài khoản nhập lại của nó bị Huỷ nhập', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $first = $this->intake->confirm($this->admin, $this->intake->submit($this->admin, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($this->accounts, 100_000, "a@shop.test\tpw", expiry: ExpiryRule::on(CarbonImmutable::parse('2026-09-20')))],
    )));
    $old = StockUnit::sole();

    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00'));
    $renewal = confirmedReversalBatch([[$this->accounts, "a@shop.test\tpw-moi"]]);

    expect($renewal->lines[0]->renewal_count)->toBe(1);

    $this->reversal->reverse($this->admin, $first);
    $this->reversal->reverse($this->admin, $renewal);

    expect($old->fresh())->status->toBe(StockUnitStatus::Reversed)->holds_dedupe_key->toBeFalse();

    $again = confirmedReversalBatch([[$this->accounts, "a@shop.test\tpw-moi"]]);

    expect($again->lines[0]->valid_count)->toBe(1)
        ->and(StockUnit::where('batch_line_id', $again->lines[0]->id)->sole()->renews_stock_unit_id)->toBeNull();
});

it('Slot bị Huỷ nhập không xem được nội dung', function () {
    confirmedReversalBatch([[$this->codes, 'AAAA-BBBB']]);
    $slot = Slot::sole();
    $this->reversal->reverse($this->admin, Batch::sole());

    expect(app(ContentReveal::class)->canRevealInStock($this->admin, $slot->fresh()))->toBeFalse()
        ->and(fn () => app(ContentReveal::class)->reveal(RevealActor::staff($this->admin), $slot, RevealContext::inStock(), 'kiểm tra'))
        ->toThrow(InvalidReveal::class);
});

it('chỉ Quản trị Huỷ nhập được', function (Role $role) {
    $batch = confirmedReversalBatch([[$this->codes, 'AAAA-BBBB']]);
    $staff = staffMember($role);

    expect(fn () => $this->reversal->plan($staff, $batch))->toThrow(MissingRole::class)
        ->and(fn () => $this->reversal->reverse($staff, $batch))->toThrow(MissingRole::class)
        ->and(StockUnit::sole()->status)->toBe(StockUnitStatus::Active);
})->with([
    'Nhập kho' => [Role::NhapKho],
    'Bán hàng' => [Role::BanHang],
]);

it('từ chối Huỷ nhập Lô nhập chưa xác nhận hoặc không còn gì để huỷ', function () {
    $pending = $this->intake->submit($this->admin, new BatchDraft($this->supplier, CarbonImmutable::parse('2026-09-15'), [new BatchLineDraft($this->codes, 1, 'AAAA-BBBB')]));

    expect(fn () => $this->reversal->reverse($this->admin, $pending))
        ->toThrow(InvalidBatch::class, 'Chỉ Huỷ nhập được Lô nhập đã xác nhận.');

    $batch = confirmedReversalBatch([[$this->codes, 'CCCC-DDDD']]);
    $this->reversal->reverse($this->admin, $batch);

    expect(fn () => $this->reversal->reverse($this->admin, $batch))
        ->toThrow(InvalidBatch::class, 'Không còn Đơn vị hàng nào Huỷ nhập được: mọi Đơn vị hàng đã bị Huỷ nhập hoặc có Slot không còn Còn hàng.');
});
