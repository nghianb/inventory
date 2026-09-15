<?php

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
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Stock\InvalidVoid;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\Product;
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

    $this->admin = staffMember(Role::QuanTri);
    $this->seller = staffMember(Role::BanHang);
    $this->voids = app(StockVoid::class);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->netflix = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
    ));

    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b")],
    )));

    // Tài khoản a@shop.test: Slot đầu Đã giao, hai Slot còn Còn hàng.
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Zalo')),
        null,
        [new DispatchLineDraft($this->netflix, 1)],
    ));
    $this->unit = StockUnit::where('content->username', 'a@shop.test')->sole();
});

function unitSlots(StockUnit $unit): array
{
    return $unit->slots()->get()->map(fn (Slot $slot) => $slot->status)->all();
}

it('Quản trị Huỷ hàng Slot Còn hàng hoặc Đã giao với lý do, ghi Sổ biến động kho; Đơn vị hàng vẫn Hoạt động', function () {
    [$delivered, $inStock] = $this->unit->slots;
    $before = StockLedgerEntry::max('id');

    $this->voids->voidSlot($this->admin, $inStock, VoidReason::ContentExposed, '  Nhân viên chụp màn hình  ');
    $this->voids->voidSlot($this->admin, $delivered, VoidReason::WrongDelivery);

    expect($inStock->fresh())
        ->status->toBe(SlotStatus::Voided)
        ->void_reason->toBe(VoidReason::ContentExposed)
        ->voided_at->toEqual(now()->toImmutable())
        ->and($delivered->fresh()->void_reason)->toBe(VoidReason::WrongDelivery)
        ->and($this->unit->fresh())->status->toBe(StockUnitStatus::Active)->holds_dedupe_key->toBeTrue()
        ->and(StockLedgerEntry::where('id', '>', $before)->orderBy('id')->get()->map(fn (StockLedgerEntry $row) => [$row->slot_id, $row->from_status, $row->to_status, $row->actor_id, $row->reason])->all())
        ->toBe([
            [$inStock->id, 'in-stock', 'voided', $this->admin->id, 'Huỷ hàng: Lộ nội dung: Nhân viên chụp màn hình'],
            [$delivered->id, 'delivered', 'voided', $this->admin->id, 'Huỷ hàng: Giao nhầm'],
        ])
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(4);
});

it('Quản trị Huỷ hàng Đơn vị hàng: Đơn vị hàng và Slot Còn hàng Đã huỷ, Slot Đã giao giữ nguyên, không giải phóng Khoá chống trùng', function () {
    $before = StockLedgerEntry::max('id');

    $this->voids->voidUnit($this->admin, $this->unit, VoidReason::DiscontinuedLot);

    expect($this->unit->fresh())
        ->status->toBe(StockUnitStatus::Voided)
        ->void_reason->toBe(VoidReason::DiscontinuedLot)
        ->voided_at->toEqual(now()->toImmutable())
        ->holds_dedupe_key->toBeTrue()
        ->and(unitSlots($this->unit))->toBe([SlotStatus::Delivered, SlotStatus::Voided, SlotStatus::Voided])
        ->and(StockLedgerEntry::where('id', '>', $before)->orderBy('id')->get()->map(fn (StockLedgerEntry $row) => [$row->slot_id === null, $row->from_status, $row->to_status, $row->reason])->all())
        ->toBe([
            [true, 'active', 'voided', 'Huỷ hàng: Ngừng kinh doanh lô'],
            [false, 'in-stock', 'voided', 'Huỷ hàng: Ngừng kinh doanh lô'],
            [false, 'in-stock', 'voided', 'Huỷ hàng: Ngừng kinh doanh lô'],
        ])
        ->and(app(SellableStock::class)->count($this->netflix))->toBe(3);
});

it('chỉ Quản trị Huỷ hàng; Slot hoặc Đơn vị hàng không còn huỷ được thì báo lỗi', function () {
    $slot = $this->unit->slots[1];

    expect($this->voids->canVoidSlot($this->admin, $slot))->toBeTrue()
        ->and($this->voids->canVoidSlot($this->seller, $slot))->toBeFalse()
        ->and($this->voids->canVoidUnit($this->seller, $this->unit))->toBeFalse()
        ->and(fn () => $this->voids->voidSlot($this->seller, $slot, VoidReason::ContentExposed))->toThrow(MissingRole::class)
        ->and(fn () => $this->voids->voidUnit($this->seller, $this->unit, VoidReason::ContentExposed))->toThrow(MissingRole::class);

    $this->voids->voidUnit($this->admin, $this->unit, VoidReason::ContentExposed);

    expect($this->voids->canVoidSlot($this->admin, $slot->fresh()))->toBeFalse()
        ->and($this->voids->canVoidUnit($this->admin, $this->unit->fresh()))->toBeFalse()
        ->and(fn () => $this->voids->voidSlot($this->admin, $slot, VoidReason::ContentExposed))->toThrow(InvalidVoid::class, 'Chỉ Huỷ hàng được Slot Còn hàng hoặc Đã giao.')
        ->and(fn () => $this->voids->voidUnit($this->admin, $this->unit, VoidReason::ContentExposed))->toThrow(InvalidVoid::class, 'Chỉ Huỷ hàng được Đơn vị hàng Hoạt động.');
});

it('Quản trị Huỷ hàng từ chi tiết Đơn vị hàng và bảng Slot; Bán hàng không thấy nút', function () {
    $slot = $this->unit->slots[1];
    $this->actingAs($this->seller);

    Livewire::test(ViewStockUnit::class, ['record' => $this->unit->getRouteKey()])->assertActionHidden('void');
    Livewire::test(SlotsRelationManager::class, ['ownerRecord' => $this->unit, 'pageClass' => ViewStockUnit::class])
        ->assertActionHidden(TestAction::make('void')->table($slot));

    $this->actingAs($this->admin);

    Livewire::test(SlotsRelationManager::class, ['ownerRecord' => $this->unit, 'pageClass' => ViewStockUnit::class])
        ->callAction(TestAction::make('void')->table($slot), data: ['reason' => null])
        ->assertHasActionErrors(['reason' => 'required'])
        ->setActionData(['reason' => VoidReason::ContentExposed->value, 'note' => 'Lộ ảnh'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($slot->fresh()->status)->toBe(SlotStatus::Voided);

    Livewire::test(ViewStockUnit::class, ['record' => $this->unit->getRouteKey()])
        ->callAction('void', data: ['reason' => VoidReason::DiscontinuedLot->value])
        ->assertHasNoActionErrors()
        ->assertActionHidden('void');

    expect($this->unit->fresh()->status)->toBe(StockUnitStatus::Voided)
        ->and(unitSlots($this->unit))->toBe([SlotStatus::Delivered, SlotStatus::Voided, SlotStatus::Voided]);
});

it('Huỷ hàng Đơn vị hàng không đụng Sản phẩm khác', function () {
    $other = StockUnit::where('content->username', 'b@shop.test')->sole();

    $this->voids->voidUnit($this->admin, $this->unit, VoidReason::ContentExposed);

    expect($other->fresh()->status)->toBe(StockUnitStatus::Active)
        ->and(unitSlots($other))->toBe([SlotStatus::InStock, SlotStatus::InStock, SlotStatus::InStock])
        ->and(Product::count())->toBe(1);
});
