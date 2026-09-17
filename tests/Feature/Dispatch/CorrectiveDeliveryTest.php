<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchEdit;
use App\Inventory\Dispatch\DispatchEditor;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\OutOfStock;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\VoidReason;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->manual = app(ManualDispatch::class);
    $this->corrective = app(CorrectiveDelivery::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee', requiresExternalRef: true));

    $catalog = app(ProductCatalog::class);
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('serial', 'Serial', sensitive: false), new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        warrantyDays: 7,
    ));
    $this->netflix = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
        warrantyDays: 30,
    ));
});

function correctionStock(Product $product, string $content): void
{
    $intake = app(BatchIntake::class);
    $intake->confirm(test()->admin, $intake->submit(test()->admin, new BatchDraft(
        supplier: test()->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($product, 90_000, $content)],
    )));
}

function correctionOrder(string $ref, Product $product, ?int $salePrice = null, ?string $customer = null): Dispatch
{
    return test()->manual->create(test()->seller, new DispatchDraft(test()->shopee, $ref, [new DispatchLineDraft($product, 1, $salePrice)], customer: $customer));
}

function soleDelivery(Dispatch $dispatch): Delivery
{
    return $dispatch->deliveries()->orderBy('deliveries.id')->firstOrFail();
}

/**
 * @return list<array{0: ?string, 1: string, 2: ?int, 3: ?string}>
 */
function ledgerSince(?int $id): array
{
    return StockLedgerEntry::where('id', '>', (int) $id)->orderBy('id')->get()
        ->map(fn (StockLedgerEntry $row) => [$row->from_status, $row->to_status, $row->slot_id === null ? 'unit' : 'slot', $row->reason])
        ->all();
}

it('Giao thay cùng Sản phẩm trong 24 giờ: Huỷ hàng Slot với lý do giao nhầm, giao Slot khác vào Dòng xuất gốc, liên kết lần giao bị huỷ; không đổi Đơn vị hàng sang Lỗi', function () {
    correctionStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    $dispatch = correctionOrder('SP-001', $this->steam, 100_000);
    $wrong = soleDelivery($dispatch);
    $this->travel(23)->hours();
    $before = StockLedgerEntry::max('id');

    $new = $this->corrective->correct($this->seller, $wrong, new CorrectionDraft(product: null, contentSent: false));

    expect($new)
        ->dispatch_line_id->toBe($wrong->dispatch_line_id)
        ->corrects_delivery_id->toBe($wrong->id)
        ->delivered_by->toBe($this->seller->id)
        ->and($new->stockUnit->content['serial'])->toBe('SR2')
        ->and($wrong->slot->fresh())
        ->status->toBe(SlotStatus::Voided)
        ->void_reason->toBe(VoidReason::WrongDelivery)
        ->voided_at->toEqual(now()->toImmutable())
        ->and($wrong->stockUnit->fresh()->status)->toBe(StockUnitStatus::Active)
        ->and(DispatchLine::sole())->kind->toBe(DispatchLineKind::Sale)->quantity->toBe(1)->sale_price->toBe(100_000)
        ->and(ledgerSince($before))->toBe([
            ['delivered', 'voided', 'slot', "Giao thay theo Phiếu xuất #{$dispatch->id}: Giao nhầm"],
            ['in-stock', 'delivered', 'slot', "Giao thay theo Phiếu xuất #{$dispatch->id}, thay lần giao #{$wrong->id}"],
        ])
        ->and(StockLedgerEntry::where('id', '>', $before)->pluck('actor_id')->unique()->all())->toBe([$this->seller->id]);
});

it('Giao thay sang Sản phẩm khác thêm Dòng xuất loại Giao thay, Giá bán trống; dòng gốc giữ nguyên số lượng', function () {
    correctionStock($this->steam, "SR1\tA-1");
    correctionStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = correctionOrder('SP-001', $this->steam, 100_000);
    $wrong = soleDelivery($dispatch);

    $new = $this->corrective->correct($this->seller, $wrong, new CorrectionDraft(product: $this->netflix, contentSent: false));

    expect($dispatch->lines()->get()->map(fn (DispatchLine $line) => [$line->product_id, $line->kind, $line->quantity, $line->sale_price])->all())
        ->toBe([
            [$this->steam->id, DispatchLineKind::Sale, 1, 100_000],
            [$this->netflix->id, DispatchLineKind::Corrective, 1, null],
        ])
        ->and($new->dispatchLine->kind)->toBe(DispatchLineKind::Corrective)
        ->and($new->corrects_delivery_id)->toBe($wrong->id)
        ->and($new->stockUnit->content['username'])->toBe('a@shop.test')
        ->and($new->warranty_days)->toBe(30)
        ->and($dispatch->fresh()->status)->toBe(DispatchStatus::Completed);
});

it('quá 24 giờ Bán hàng không Giao thay được; Quản trị Giao thay được kèm lý do; số giờ cấu hình được', function () {
    correctionStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3\nSR4\tA-4");
    $dispatch = correctionOrder('SP-001', $this->steam);
    $wrong = soleDelivery($dispatch);
    $this->travel(24)->hours();
    $this->travel(1)->seconds();
    $draft = new CorrectionDraft(product: null, contentSent: false);

    expect($this->corrective->preview($this->seller, $wrong))
        ->late->toBeTrue()
        ->deadline->toEqual(CarbonImmutable::parse('2026-09-16 10:00'))
        ->and($this->corrective->canCorrect($this->seller, $wrong))->toBeFalse()
        ->and($this->corrective->canCorrect($this->admin, $wrong))->toBeTrue()
        ->and(fn () => $this->corrective->correct($this->seller, $wrong, $draft))
        ->toThrow(InvalidDispatch::class, 'Đã quá 24 giờ kể từ lúc giao; chỉ Quản trị Giao thay được.')
        ->and(fn () => $this->corrective->correct($this->admin, $wrong, $draft))
        ->toThrow(InvalidDispatch::class, 'Giao thay quá 24 giờ phải nhập lý do.')
        ->and(Delivery::count())->toBe(1);

    $before = StockLedgerEntry::max('id');
    $this->corrective->correct($this->admin, $wrong, new CorrectionDraft(product: null, contentSent: false, reason: '  Khách báo nhầm mã  '));

    expect(ledgerSince($before)[0][3])->toBe("Giao thay theo Phiếu xuất #{$dispatch->id}: Giao nhầm: Khách báo nhầm mã");

    config(['inventory.dispatch.corrective_hours' => 48]);
    $other = soleDelivery(correctionOrder('SP-002', $this->steam));
    $this->travel(47)->hours();

    expect($this->corrective->preview($this->seller, $other))->late->toBeFalse()
        ->and($this->corrective->correct($this->seller, $other, $draft)->corrects_delivery_id)->toBe($other->id);
});

it('nội dung đã gửi và Huỷ hàng cả Đơn vị hàng: liệt kê Lần giao bị ảnh hưởng, Slot còn trong kho Đã huỷ, không giải phóng Khoá chống trùng, không tự Báo lỗi', function () {
    correctionStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $other = correctionOrder('SP-000', $this->netflix, customer: 'Chị Lan');
    $dispatch = correctionOrder('SP-001', $this->netflix, customer: 'Anh Minh');
    $wrong = soleDelivery($dispatch);
    $unit = $wrong->stockUnit;
    $before = StockLedgerEntry::max('id');

    expect($wrong->stock_unit_id)->toBe(soleDelivery($other)->stock_unit_id)
        ->and($this->corrective->preview($this->seller, $wrong)->affected)->toEqual([
            new AffectedDelivery(
                deliveryId: soleDelivery($other)->id,
                dispatchId: $other->id,
                externalRef: 'SP-000',
                customer: 'Chị Lan',
                channelName: 'Shopee',
                slotId: soleDelivery($other)->slot_id,
                deliveredAt: CarbonImmutable::parse('2026-09-15 10:00'),
            ),
        ]);

    $new = $this->corrective->correct($this->seller, $wrong, new CorrectionDraft(product: null, contentSent: true, voidUnit: true));
    $unit->refresh();

    expect($unit)
        ->status->toBe(StockUnitStatus::Voided)
        ->void_reason->toBe(VoidReason::WrongDelivery)
        ->holds_dedupe_key->toBeTrue()
        ->and($unit->slots->map(fn (Slot $slot) => $slot->status)->all())
        ->toBe([SlotStatus::Delivered, SlotStatus::Voided, SlotStatus::Voided])
        ->and($new->stockUnit->content['username'])->toBe('b@shop.test')
        ->and(ledgerSince($before))->toBe([
            ['delivered', 'voided', 'slot', "Giao thay theo Phiếu xuất #{$dispatch->id}: Giao nhầm"],
            ['active', 'voided', 'unit', "Giao thay theo Phiếu xuất #{$dispatch->id}: Giao nhầm"],
            ['in-stock', 'voided', 'slot', "Giao thay theo Phiếu xuất #{$dispatch->id}: Giao nhầm"],
            ['in-stock', 'delivered', 'slot', "Giao thay theo Phiếu xuất #{$dispatch->id}, thay lần giao #{$wrong->id}"],
        ]);
});

it('Giao thay không chọn lại Đơn vị hàng vừa giao nhầm; Giữ nguyên thì Đơn vị hàng vẫn Hoạt động và vẫn bán cho đơn khác', function () {
    correctionStock($this->netflix, "a@shop.test\tpw-a\nb@shop.test\tpw-b");
    $wrong = soleDelivery(correctionOrder('SP-001', $this->netflix));

    $new = $this->corrective->correct($this->seller, $wrong, new CorrectionDraft(product: null, contentSent: true));

    expect($wrong->stockUnit->fresh()->status)->toBe(StockUnitStatus::Active)
        ->and($new->stockUnit->content['username'])->toBe('b@shop.test')
        // Hai Slot còn lại của Tài khoản a vẫn thuộc Tồn bán được, cùng hai Slot còn lại của b.
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(4);
});

it('kho chỉ còn Slot của Đơn vị hàng vừa giao nhầm thì Giao thay báo thiếu hàng', function () {
    correctionStock($this->netflix, "a@shop.test\tpw-a");
    $wrong = soleDelivery(correctionOrder('SP-001', $this->netflix));

    expect(fn () => $this->corrective->correct($this->seller, $wrong, new CorrectionDraft(product: null, contentSent: false)))
        ->toThrow(OutOfStock::class, 'Không đủ hàng, không giao gì: "Netflix 1 tháng" cần 1, còn 0.')
        ->and($wrong->slot->fresh()->status)->toBe(SlotStatus::Delivered);
});

it('Sản phẩm gốc đã Ngừng bán vẫn Giao thay cùng Sản phẩm được', function () {
    correctionStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    $wrong = soleDelivery(correctionOrder('SP-001', $this->steam));
    app(ProductCatalog::class)->discontinue($this->admin, $this->steam);

    $new = $this->corrective->correct($this->seller, $wrong, new CorrectionDraft(product: null, contentSent: false));

    expect($new->stockUnit->content['serial'])->toBe('SR2')
        ->and($new->dispatch_line_id)->toBe($wrong->dispatch_line_id);
});

it('Giao thay báo lỗi và không đổi gì', function (Closure $arrange, string $exception, string $message) {
    correctionStock($this->steam, "SR1\tA-1\nSR2\tA-2");
    correctionStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = correctionOrder('SP-001', $this->steam);
    [$actor, $draft] = $arrange($dispatch, soleDelivery($dispatch));
    $snapshot = fn () => [
        DB::table('dispatch_lines')->orderBy('id')->get()->all(),
        DB::table('deliveries')->orderBy('id')->get()->all(),
        DB::table('slots')->orderBy('id')->get()->all(),
        DB::table('stock_units')->orderBy('id')->get(['id', 'status'])->all(),
        StockLedgerEntry::count(),
    ];
    $before = $snapshot();

    expect(fn () => $this->corrective->correct($actor, soleDelivery($dispatch), $draft))->toThrow($exception, $message)
        ->and($snapshot())->toEqual($before);
})->with([
    'Huỷ cả Đơn vị hàng khi nội dung chưa gửi' => [fn () => [test()->seller, new CorrectionDraft(null, contentSent: false, voidUnit: true)], InvalidDispatch::class, 'Chỉ Huỷ hàng cả Đơn vị hàng khi nội dung đã gửi cho khách.'],
    'thiếu hàng' => [function () {
        correctionOrder('SP-002', test()->steam);

        return [test()->seller, new CorrectionDraft(null, contentSent: false)];
    }, OutOfStock::class, 'Không đủ hàng, không giao gì: "Steam Wallet 100k" cần 1, còn 0.'],
    'Sản phẩm Ngừng bán' => [function () {
        app(ProductCatalog::class)->discontinue(test()->admin, test()->netflix);

        return [test()->seller, new CorrectionDraft(test()->netflix, contentSent: false)];
    }, InvalidDispatch::class, 'Sản phẩm "Netflix 1 tháng" đã Ngừng bán.'],
    'lần giao đã bị huỷ' => [function (Dispatch $dispatch, Delivery $delivery) {
        DB::table('slots')->where('id', $delivery->slot_id)->update(['status' => SlotStatus::Voided->value]);

        return [test()->seller, new CorrectionDraft(null, contentSent: false)];
    }, InvalidDispatch::class, 'Lần giao này đã bị huỷ; không Giao thay được nữa.'],
    'phiếu không Hoàn tất' => [function (Dispatch $dispatch) {
        DB::table('dispatches')->where('id', $dispatch->id)->update(['status' => DispatchStatus::Cancelled->value]);

        return [test()->seller, new CorrectionDraft(null, contentSent: false)];
    }, InvalidDispatch::class, 'Chỉ Giao thay được trên Phiếu xuất Hoàn tất.'],
    'Nhập kho' => [fn () => [staffMember(Role::NhapKho), new CorrectionDraft(null, contentSent: false)], MissingRole::class, ''],
]);

it('Sửa phiếu không đặt được Giá bán cho Dòng xuất loại Giao thay; để trống vẫn lưu được', function () {
    correctionStock($this->steam, "SR1\tA-1");
    correctionStock($this->netflix, "a@shop.test\tpw-a");
    $dispatch = correctionOrder('SP-001', $this->steam, 100_000);
    $new = $this->corrective->correct($this->seller, soleDelivery($dispatch), new CorrectionDraft(product: $this->netflix, contentSent: false));
    $editor = app(DispatchEditor::class);

    expect(fn () => $editor->edit($this->seller, $dispatch, new DispatchEdit('SP-001', null, null, [$new->dispatch_line_id => 50_000])))
        ->toThrow(InvalidDispatch::class, 'Dòng xuất loại Giao thay không có Giá bán.')
        ->and($new->dispatchLine->fresh()->sale_price)->toBeNull()
        ->and($editor->edit($this->seller, $dispatch, new DispatchEdit('SP-001', null, null, [$new->dispatch_line_id => null]))->id)->toBe($dispatch->id);
});

it('lần giao đã được Giao thay thì không Giao thay lại được; lần giao mới thì được', function () {
    correctionStock($this->steam, "SR1\tA-1\nSR2\tA-2\nSR3\tA-3");
    $wrong = soleDelivery(correctionOrder('SP-001', $this->steam));
    $draft = new CorrectionDraft(product: null, contentSent: false);

    $new = $this->corrective->correct($this->seller, $wrong, $draft);

    expect($this->corrective->canCorrect($this->seller, $wrong->fresh()))->toBeFalse()
        ->and(fn () => $this->corrective->correct($this->seller, $wrong, $draft))->toThrow(InvalidDispatch::class, 'Lần giao này đã bị huỷ; không Giao thay được nữa.')
        ->and($this->corrective->correct($this->seller, $new, $draft)->corrects_delivery_id)->toBe($new->id)
        ->and(StockUnit::where('status', StockUnitStatus::Active)->count())->toBe(3);
});
