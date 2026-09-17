<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockUnits\Pages\ViewStockUnit;
use App\Filament\Resources\StockUnits\RelationManagers\SlotsRelationManager;
use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Stock\InvalidDefectMarking;
use App\Inventory\Stock\InvalidVoid;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectReportStatus;
use App\Inventory\Warranty\DefectResolution;
use App\Inventory\Warranty\DefectScope;
use App\Models\Delivery;
use App\Models\Slot;
use App\Models\StockLedgerEntry;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->seller = staffMember(Role::BanHang);
    $this->defects = app(StockDefect::class);
    $this->stock = app(SellableStock::class);
    $this->netflix = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
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

    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: app(SupplierDirectory::class)->create($this->admin, 'Kinguin'),
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b")],
    )));

    // Tài khoản a@shop.test: Slot đầu Đã giao, hai Slot còn Còn hàng.
    $this->dispatch = app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo')),
        null,
        [new DispatchLineDraft($this->netflix, 1)],
        customer: 'Anh Minh',
    ));
    $this->unit = StockUnit::where('content->username', 'a@shop.test')->sole();
});

/**
 * @return list<array{int, ?string}> id Slot và thời điểm tính Tổn thất hàng Lỗi
 */
function defectiveLoss(StockUnit $unit): array
{
    return $unit->slots()->get()->map(fn (Slot $slot) => [$slot->id, $slot->defective_loss_at?->toDateTimeString()])->all();
}

it('Quản trị Đánh dấu Lỗi: Đơn vị hàng chuyển Lỗi, ghi Sổ biến động kho, lưu Slot còn trong kho để tính Tổn thất, trả Lần giao bị ảnh hưởng', function () {
    [$delivered, $second, $third] = $this->unit->slots;
    $delivery = Delivery::where('slot_id', $delivered->id)->sole();
    $before = StockLedgerEntry::max('id');

    $affected = $this->defects->markDefective($this->admin, $this->unit, '  Nhà cung cấp thu hồi  ');

    expect($this->unit->fresh())
        ->status->toBe(StockUnitStatus::Defective)
        ->defective_at->toEqual(now()->toImmutable())
        ->and(defectiveLoss($this->unit))->toBe([
            [$delivered->id, null],
            [$second->id, '2026-09-15 10:00:00'],
            [$third->id, '2026-09-15 10:00:00'],
        ])
        ->and(StockLedgerEntry::where('id', '>', $before)->get()->map(fn (StockLedgerEntry $row) => [$row->stock_unit_id, $row->slot_id, $row->from_status, $row->to_status, $row->actor_id, $row->reason])->all())
        ->toBe([[$this->unit->id, null, 'active', 'defective', $this->admin->id, 'Đánh dấu Lỗi: Nhà cung cấp thu hồi']])
        ->and(array_map(fn (AffectedDelivery $row) => [$row->deliveryId, $row->customer], $affected))->toBe([[$delivery->id, 'Anh Minh']])
        // Tồn lỗi tách khỏi Tồn bán được: chỉ còn 3 Slot của Tài khoản b bán được.
        ->and($this->stock->count($this->netflix))->toBe(3)
        ->and($this->stock->defectiveCount($this->netflix))->toBe(2);
});

it('Quản trị Khôi phục Đơn vị hàng Lỗi kèm lý do: bán lại được, bỏ Tổn thất hàng Lỗi, Báo lỗi đã làm giữ nguyên', function () {
    $delivery = Delivery::where('stock_unit_id', $this->unit->id)->sole();
    $reports = app(DefectReporting::class);
    [$report] = $reports->report($this->seller, [$delivery], new DefectReportDraft('Tài khoản bị khoá'));
    $reports->confirm($this->seller, $report, DefectScope::Unit, 'Đăng nhập thử bị khoá');
    $this->travel(2)->days();

    expect(defectiveLoss($this->unit)[1][1])->toBe('2026-09-15 10:00:00');

    $before = StockLedgerEntry::max('id');
    $this->defects->restore($this->admin, $this->unit, ' Nhà cung cấp mở khoá ');

    expect($this->unit->fresh())
        ->status->toBe(StockUnitStatus::Active)
        ->defective_at->toBeNull()
        ->and(collect(defectiveLoss($this->unit))->pluck(1)->all())->toBe([null, null, null])
        ->and(StockLedgerEntry::where('id', '>', $before)->get()->map(fn (StockLedgerEntry $row) => [$row->slot_id, $row->from_status, $row->to_status, $row->actor_id, $row->reason])->all())
        ->toBe([[null, 'defective', 'active', $this->admin->id, 'Khôi phục: Nhà cung cấp mở khoá']])
        ->and($report->fresh())->status->toBe(DefectReportStatus::Confirmed)->scope->toBe(DefectScope::Unit)->resolution->toBe(DefectResolution::AwaitingReplacement)
        ->and($this->stock->count($this->netflix))->toBe(5)
        ->and($this->stock->defectiveCount($this->netflix))->toBe(0);

    // Đơn vị hàng Hoạt động lại: phiếu sau lấy Slot giao dở của Tài khoản a.
    $next = app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->dispatch->salesChannel, null, [new DispatchLineDraft($this->netflix, 1)]));

    expect($next->deliveries()->sole()->stock_unit_id)->toBe($this->unit->id);
});

it('Khôi phục rồi Đánh dấu Lỗi lại ghi mốc Tổn thất mới cho Slot còn trong kho lúc đó', function () {
    $this->defects->markDefective($this->admin, $this->unit, 'Hỏng');
    $this->defects->restore($this->admin, $this->unit, 'Sửa xong');
    // Slot thứ hai của Tài khoản a được giao trong lúc Hoạt động lại.
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft($this->dispatch->salesChannel, null, [new DispatchLineDraft($this->netflix, 1)]));
    $this->travel(1)->days();

    $this->defects->markDefective($this->admin, $this->unit, 'Hỏng lại');

    expect(collect(defectiveLoss($this->unit))->pluck(1)->all())->toBe([null, null, '2026-09-16 10:00:00'])
        ->and($this->unit->fresh()->defective_at)->toEqual(CarbonImmutable::parse('2026-09-16 10:00'));
});

it('Đơn vị hàng đã quá Hạn sử dụng lúc chuyển Lỗi không ghi mốc Tổn thất hàng Lỗi: đã là Tổn thất hết hạn', function () {
    $expired = StockUnit::where('content->username', 'b@shop.test')->sole();
    $expired->forceFill(['expires_on' => CarbonImmutable::yesterday()])->save();

    $this->defects->markDefective($this->admin, $expired, 'Hỏng');

    expect($expired->fresh()->status)->toBe(StockUnitStatus::Defective)
        ->and(collect(defectiveLoss($expired))->pluck(1)->all())->toBe([null, null, null]);
});

it('chỉ Quản trị Đánh dấu Lỗi và Khôi phục; lý do bắt buộc; sai trạng thái thì báo lỗi và không đổi gì', function () {
    expect($this->defects->canMarkDefective($this->admin, $this->unit))->toBeTrue()
        ->and($this->defects->canMarkDefective($this->seller, $this->unit))->toBeFalse()
        ->and($this->defects->canRestore($this->admin, $this->unit))->toBeFalse()
        ->and(fn () => $this->defects->markDefective($this->seller, $this->unit, 'Hỏng'))->toThrow(MissingRole::class)
        ->and(fn () => $this->defects->markDefective($this->admin, $this->unit, '   '))->toThrow(InvalidDefectMarking::class, 'Đánh dấu Lỗi phải nhập lý do.')
        ->and(fn () => $this->defects->restore($this->admin, $this->unit, 'Sửa xong'))->toThrow(InvalidDefectMarking::class, 'Chỉ Khôi phục được Đơn vị hàng Lỗi.')
        ->and($this->unit->fresh()->status)->toBe(StockUnitStatus::Active);

    $this->defects->markDefective($this->admin, $this->unit, 'Hỏng');

    expect($this->defects->canMarkDefective($this->admin, $this->unit->fresh()))->toBeFalse()
        ->and($this->defects->canRestore($this->admin, $this->unit->fresh()))->toBeTrue()
        ->and($this->defects->canRestore($this->seller, $this->unit->fresh()))->toBeFalse()
        ->and(fn () => $this->defects->restore($this->seller, $this->unit, 'Sửa xong'))->toThrow(MissingRole::class)
        ->and(fn () => $this->defects->restore($this->admin, $this->unit, ''))->toThrow(InvalidDefectMarking::class, 'Khôi phục phải nhập lý do.')
        ->and(fn () => $this->defects->markDefective($this->admin, $this->unit, 'Hỏng'))->toThrow(InvalidDefectMarking::class, 'Chỉ Đánh dấu Lỗi được Đơn vị hàng Hoạt động.');

    app(StockVoid::class)->voidUnit($this->admin, $other = StockUnit::where('content->username', 'b@shop.test')->sole(), VoidReason::DiscontinuedLot);

    expect(fn () => $this->defects->markDefective($this->admin, $other, 'Hỏng'))->toThrow(InvalidDefectMarking::class, 'Chỉ Đánh dấu Lỗi được Đơn vị hàng Hoạt động.');
});

it('Slot Còn hàng của Đơn vị hàng Lỗi đã tính Tổn thất hàng Lỗi nên không Huỷ hàng được; Slot Đã giao vẫn huỷ được', function () {
    [$delivered, $inStock] = $this->unit->slots;
    $this->defects->markDefective($this->admin, $this->unit, 'Hỏng');
    $voids = app(StockVoid::class);

    expect($voids->canVoidSlot($this->admin, $inStock->fresh()))->toBeFalse()
        ->and(fn () => $voids->voidSlot($this->admin, $inStock, VoidReason::ContentExposed))
        ->toThrow(InvalidVoid::class, 'Slot Còn hàng của Đơn vị hàng Lỗi là Tồn lỗi; Khôi phục Đơn vị hàng trước khi Huỷ hàng.')
        ->and($voids->canVoidSlot($this->admin, $delivered->fresh()))->toBeTrue();
});

it('Quản trị Đánh dấu Lỗi và Khôi phục ở chi tiết Đơn vị hàng; Bán hàng không thấy nút', function () {
    $this->actingAs($this->seller);

    Livewire::test(ViewStockUnit::class, ['record' => $this->unit->getRouteKey()])
        ->assertActionHidden('markDefective')
        ->assertActionHidden('restore');

    $this->actingAs($this->admin);

    Livewire::test(ViewStockUnit::class, ['record' => $this->unit->getRouteKey()])
        ->assertActionHidden('restore')
        ->callAction('markDefective', data: ['reason' => null])
        ->assertHasActionErrors(['reason' => 'required'])
        ->setActionData(['reason' => 'Nhà cung cấp thu hồi'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionHidden('markDefective')
        ->assertActionHidden('void')
        ->assertSee('Anh Minh');

    expect($this->unit->fresh()->status)->toBe(StockUnitStatus::Defective);

    Livewire::test(SlotsRelationManager::class, ['ownerRecord' => $this->unit->fresh(), 'pageClass' => ViewStockUnit::class])
        ->assertActionHidden(TestAction::make('void')->table($this->unit->slots[1]));

    Livewire::test(ViewStockUnit::class, ['record' => $this->unit->getRouteKey()])
        ->callAction('restore', data: ['reason' => 'Nhà cung cấp sửa xong'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('restore')
        ->assertActionVisible('markDefective');

    expect($this->unit->fresh()->status)->toBe(StockUnitStatus::Active);
});

it('bảng Sản phẩm hiện Tồn lỗi riêng', function () {
    $this->defects->markDefective($this->admin, $this->unit, 'Hỏng');
    $this->actingAs($this->admin);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('defective_stock_slots_count', 2, $this->netflix);
});

it('Báo lỗi Xác nhận cả Đơn vị hàng cũng lưu Slot còn trong kho để tính Tổn thất hàng Lỗi', function () {
    $delivery = Delivery::where('stock_unit_id', $this->unit->id)->sole();
    $reports = app(DefectReporting::class);
    [$report] = $reports->report($this->seller, [$delivery], new DefectReportDraft('Tài khoản bị khoá'));
    $this->travel(3)->hours();

    $reports->confirm($this->seller, $report, DefectScope::Unit, 'Bị khoá');

    expect($this->unit->fresh()->defective_at)->toEqual(CarbonImmutable::parse('2026-09-15 13:00'))
        ->and(collect(defectiveLoss($this->unit))->pluck(1)->all())->toBe([null, '2026-09-15 13:00:00', '2026-09-15 13:00:00']);
});
