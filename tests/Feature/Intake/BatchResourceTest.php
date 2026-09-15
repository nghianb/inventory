<?php

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ProductCatalog;
use App\Inventory\Catalog\ProductDraft;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\BatchStatus;
use App\Models\Batch;
use App\Models\Product;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::QuanTri);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->product = app(ProductCatalog::class)->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam Wallet 100k',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));
});

it('Quản trị và Nhập kho vào được trang Lô nhập, Bán hàng thì không; mọi Vai trò xem được Đơn vị hàng', function (Role $role, bool $seesBatches) {
    $this->actingAs(staffMember($role));

    $this->get(BatchResource::getUrl('index'))->assertStatus($seesBatches ? 200 : 403);
    $this->get(BatchResource::getUrl('create'))->assertStatus($seesBatches ? 200 : 403);
    $this->get(StockUnitResource::getUrl('index'))->assertOk();
})->with([
    'Quản trị' => [Role::QuanTri, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, false],
]);

it('Nhập kho dán hàng từ panel, xem trước rồi xác nhận', function () {
    $this->actingAs(staffMember(Role::NhapKho));

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'received_on' => '2026-09-15',
            'document_number' => 'HD-0915',
            'product_id' => $this->product->id,
            'unit_cost' => 95000,
            'separator' => 'tab',
            'content' => "AAAA-BBBB\nCCCC-DDDD\naaaabbbb",
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::sole();

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSee('Trùng Khoá chống trùng với dòng 1.')
        ->assertSee('Mã thẻ: ••••••')
        ->assertDontSee('AAAA-BBBB')
        ->callAction('confirm')
        ->assertHasNoActionErrors()
        ->assertActionHidden('confirm');

    expect($batch->fresh()->status)->toBe(BatchStatus::Confirmed)
        ->and(StockUnit::count())->toBe(2);
});

it('panel báo lỗi nghiệp vụ và không tạo Lô nhập khi khoá mã hoá không khớp', function () {
    $this->actingAs(staffMember(Role::NhapKho));
    config(['inventory.keys.hmac' => '1:base64:+aDuV69xpMmq8HrVKo6jtB43YKS+Sd8UDwlDy4k5kgk=']);

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'received_on' => '2026-09-15',
            'product_id' => $this->product->id,
            'unit_cost' => 50000,
            'separator' => 'tab',
            'content' => 'AAAA-BBBB',
        ])
        ->call('create')
        ->assertNotified('Khoá mã hoá không khớp với DB, từ chối ghi: khoá mã hoá HMAC phiên bản 1: dấu vân tay không khớp với DB.');

    expect(Batch::count())->toBe(0);
});

it('danh sách Đơn vị hàng hiện nội dung dạng che; Bán hàng không thấy Giá vốn và Nhà cung cấp', function (Role $role, bool $seesCost) {
    $intake = app(BatchIntake::class);
    $batch = $intake->submit($this->admin, new BatchDraft(
        supplier: $this->supplier,
        receivedOn: CarbonImmutable::parse('2026-09-15'),
        lines: [new BatchLineDraft($this->product, 95_000, 'AAAA-BBBB')],
    ));
    $intake->confirm($this->admin, $batch);

    $this->actingAs(staffMember($role));

    Livewire::test(ListStockUnits::class)
        ->assertCanSeeTableRecords(StockUnit::all())
        ->assertSee('Mã thẻ: ••••••')
        ->assertDontSee('AAAA-BBBB')
        ->{$seesCost ? 'assertTableColumnVisible' : 'assertTableColumnHidden'}('unit_cost')
        ->{$seesCost ? 'assertTableColumnVisible' : 'assertTableColumnHidden'}('batchLine.batch.supplier.name');

    expect(Product::withCount('inStockSlots')->find($this->product->id)->in_stock_slots_count)->toBe(1);
})->with([
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, false],
]);
