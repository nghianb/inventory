<?php

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\ViewBatch;
use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Catalog\SupplierDirectory;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Intake\BatchDraft;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineDraft;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    Repeater::fake();
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->supplier = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->product = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam Wallet 100k',
        'STEAM-100K',
    );
});

it('Quản trị và Nhập kho vào được trang Lô nhập, Bán hàng thì không; mọi Vai trò xem được Đơn vị hàng', function (Role $role, bool $seesBatches) {
    $this->actingAs(staffMember($role));

    $this->get(BatchResource::getUrl('index'))->assertStatus($seesBatches ? 200 : 403);
    $this->get(BatchResource::getUrl('create'))->assertStatus($seesBatches ? 200 : 403);
    $this->get(StockUnitResource::getUrl('index'))->assertOk();
})->with([
    'Quản trị' => [Role::Owner, true],
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
            'lines' => [[
                'product_id' => $this->product->id,
                'unit_cost' => 95000,
                'source' => 'paste',
                'separator' => 'tab',
                'content' => "AAAA-BBBB\nCCCC-DDDD\naaaabbbb",
            ]],
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

it('Nhập kho tạo nhanh Nhà cung cấp, upload file Tài khoản kèm Dòng nhập dán; trùng trong kho phải tick khi xác nhận', function () {
    $this->actingAs(staffMember(Role::NhapKho));
    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft($this->supplier, CarbonImmutable::parse('2026-09-15'), [new BatchLineDraft($this->product, 1, 'AAAA-BBBB')])));
    $netflix = productOf(
        StockForm::Account,
        [new ContentFieldDraft('username', 'Tên đăng nhập', dedupeKey: true, sensitive: false), new ContentFieldDraft('password', 'Mật khẩu')],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 4,
    );

    Livewire::test(CreateBatch::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('supplier_id'), data: ['name' => 'G2A'])
        ->assertHasNoActionErrors()
        ->assertSchemaStateSet(['supplier_id' => Supplier::where('name', 'G2A')->sole()->id])
        ->fillForm([
            'received_on' => '2026-09-16',
            'invoice_total' => 400000,
            'lines' => [
                [
                    'product_id' => $netflix->id,
                    'unit_cost' => 100000,
                    'slots' => 2,
                    'expiry_mode' => 'days',
                    'expires_after_days' => 30,
                    'source' => 'file',
                    'file' => UploadedFile::fake()->createWithContent('netflix.csv', "Tên đăng nhập,password,Ghi chú\na@shop.test,pw1,x\nb@shop.test,pw2,y\n"),
                ],
                [
                    'product_id' => $this->product->id,
                    'unit_cost' => 95000,
                    'expiry_mode' => 'none',
                    'source' => 'paste',
                    'separator' => 'tab',
                    'content' => "aaaa-bbbb\nEEEE-FFFF",
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::latest('id')->firstOrFail();

    expect($batch->supplier->name)->toBe('G2A');

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->assertSee('File CSV: netflix.csv')
        ->assertSee('Ghi chú')
        ->assertSee('a@shop.test')
        ->assertDontSee('pw1')
        ->callAction('confirm')
        ->assertHasActionErrors(['skip_stock_duplicates'])
        ->setActionData(['skip_stock_duplicates' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($batch->fresh()->status)->toBe(BatchStatus::Confirmed)
        ->and(StockUnit::count())->toBe(4)
        ->and(StockUnit::where('kind', StockForm::Account)->sum('slot_count'))->toBe(4)
        ->and(Storage::disk('intake')->allFiles('batch-lines'))->toBe([]);
});

it('Nhập kho bỏ Lô nhập chưa xác nhận từ panel', function () {
    $this->actingAs(staffMember(Role::NhapKho));
    $batch = app(BatchIntake::class)->submit($this->admin, new BatchDraft($this->supplier, CarbonImmutable::parse('2026-09-15'), [new BatchLineDraft($this->product, 1, 'AAAA-BBBB')]));

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->callAction('discard')
        ->assertHasNoActionErrors()
        ->assertActionHidden('confirm')
        ->assertActionHidden('discard');

    expect($batch->fresh()->status)->toBe(BatchStatus::Discarded)
        ->and(Storage::disk('intake')->allFiles())->toBe([]);
});

it('Quản trị Huỷ nhập từ panel sau khi xem số lượng sẽ huỷ và giữ lại; Nhập kho không thấy nút', function () {
    $intake = app(BatchIntake::class);
    $batch = $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft($this->supplier, CarbonImmutable::parse('2026-09-15'), [new BatchLineDraft($this->product, 1, "AAAA-BBBB\nCCCC-DDDD")])));
    // Chưa có Giữ hàng trong module: đặt trạng thái trực tiếp.
    Slot::whereKey(Slot::orderBy('id')->firstOrFail()->id)->update(['status' => SlotStatus::Reserved]);

    $this->actingAs(staffMember(Role::NhapKho));

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])->assertActionHidden('reverse');

    $this->actingAs($this->admin);

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->mountAction('reverse')
        ->assertMountedActionModalSee('Sẽ Huỷ nhập 1 Đơn vị hàng (1 Slot). Giữ lại 1 Đơn vị hàng')
        ->setActionData(['target' => $batch->lines[0]->id, 'reason' => 'Nhà cung cấp gửi nhầm'])
        ->assertMountedActionModalSee('Sẽ Huỷ nhập 1 Đơn vị hàng (1 Slot). Giữ lại 1 Đơn vị hàng')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Đã Huỷ nhập 1 Đơn vị hàng; giữ lại 1 Đơn vị hàng.')
        ->assertSee('Đã Huỷ nhập');

    expect(StockUnit::where('status', StockUnitStatus::Reversed)->count())->toBe(1)
        ->and($batch->lines[0]->fresh()->reversed_count)->toBe(1);

    Livewire::test(ViewBatch::class, ['record' => $batch->getRouteKey()])
        ->callAction('reverse', data: ['target' => 'batch'])
        ->assertNotified('Không còn Đơn vị hàng nào Huỷ nhập được: mọi Đơn vị hàng đã bị Huỷ nhập hoặc có Slot không còn Còn hàng.');
});

it('panel báo lỗi nghiệp vụ và không tạo Lô nhập khi khoá mã hoá không khớp', function () {
    $this->actingAs(staffMember(Role::NhapKho));
    config(['inventory.keys.hmac' => '1:base64:+aDuV69xpMmq8HrVKo6jtB43YKS+Sd8UDwlDy4k5kgk=']);

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'supplier_id' => $this->supplier->id,
            'received_on' => '2026-09-15',
            'lines' => [[
                'product_id' => $this->product->id,
                'unit_cost' => 50000,
                'source' => 'paste',
                'separator' => 'tab',
                'content' => 'AAAA-BBBB',
            ]],
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
