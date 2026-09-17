<?php

use App\Filament\Pages\DispatchProfitReportPage;
use App\Filament\Pages\ProfitReportPage;
use App\Filament\Pages\SupplierLossReportPage;
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
use App\Inventory\Stock\StockDefect;
use App\Models\Dispatch;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);

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

    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));

    // Giá vốn Slot 30.000: mỗi Tài khoản 3 Slot, Giá vốn 90.000.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a\nb@shop.test\tpw-b")],
    )));

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-1',
        [new DispatchLineDraft($this->netflix, 1, 500_000)],
    ));
    app(StockDefect::class)->markDefective($this->admin, StockUnit::where('content->username', 'b@shop.test')->sole(), 'Nhà cung cấp thu hồi');

    $this->productRow = "san-pham-{$this->netflix->id}";
    $this->supplierRow = "nha-cung-cap-{$this->kinguin->id}";
    $this->dispatchRow = 'phieu-xuat-'.Dispatch::sole()->id;
});

it('chỉ Quản trị vào được ba trang báo cáo Lãi/lỗ', function (?Role $role, bool $sees) {
    $this->actingAs($role === null ? User::factory()->withTwoFactor()->create() : staffMember($role));

    foreach ([ProfitReportPage::getUrl(), DispatchProfitReportPage::getUrl(), SupplierLossReportPage::getUrl()] as $url) {
        $this->get($url)->assertStatus($sees ? 200 : 403);
    }
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, false],
    'Bán hàng' => [Role::BanHang, false],
    'không vai trò' => [null, false],
]);

it('bảng Lãi/lỗ hiện số liệu của ProfitReport, kèm dòng tổng', function () {
    $this->actingAs($this->admin);

    Livewire::test(ProfitReportPage::class)
        ->assertCanSeeTableRecords([$this->productRow, 'tong'], inOrder: true)
        ->assertTableColumnStateSet('revenue', 500_000, $this->productRow)
        ->assertTableColumnStateSet('cogs', 30_000, $this->productRow)
        ->assertTableColumnStateSet('gross_profit', 470_000, $this->productRow)
        ->assertTableColumnStateSet('margin', '94,0%', $this->productRow)
        // 3 Slot của Đơn vị hàng chuyển Lỗi, Giá vốn Slot 30.000.
        ->assertTableColumnStateSet('defective_loss', 90_000, $this->productRow)
        ->assertTableColumnStateSet('net_profit', 380_000, $this->productRow)
        ->assertTableColumnStateSet('code', 'Tổng', 'tong');
});

it("dòng 'Chưa có Giá bán' hiện số Slot và Giá vốn, để trống các ô chưa biết", function () {
    $this->actingAs($this->admin);
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-2',
        [new DispatchLineDraft($this->netflix, 2)],
    ));

    Livewire::test(ProfitReportPage::class)
        ->assertCanSeeTableRecords(['chua-co-gia-ban'])
        ->assertTableColumnStateSet('code', 'Chưa có Giá bán', 'chua-co-gia-ban')
        ->assertTableColumnStateSet('name', '2 Slot', 'chua-co-gia-ban')
        ->assertTableColumnStateSet('cogs', 60_000, 'chua-co-gia-ban')
        ->assertTableColumnStateSet('revenue', '', 'chua-co-gia-ban');
});

it('lọc Kênh bán ẩn cột Điều chỉnh và Lãi ròng kho', function () {
    $this->actingAs($this->admin);

    Livewire::test(ProfitReportPage::class)
        ->filterTable('channel', $this->shopee->id)
        ->assertTableColumnVisible('gross_profit')
        // Trang khai báo đủ cột rồi bật/tắt theo ProfitReport::columns(), nên cột vẫn tồn tại, chỉ ẩn.
        ->assertTableColumnHidden('adjustments')
        ->assertTableColumnHidden('net_profit');
});

it('bảng chi tiết theo Phiếu xuất hiện Giá bán, Giá vốn và Lãi gộp', function () {
    $this->actingAs($this->admin);

    Livewire::test(DispatchProfitReportPage::class)
        ->assertCanSeeTableRecords([$this->dispatchRow])
        ->assertTableColumnStateSet('external_ref', 'SP-1', $this->dispatchRow)
        ->assertTableColumnStateSet('sale_price', 500_000, $this->dispatchRow)
        ->assertTableColumnStateSet('gross_profit', 470_000, $this->dispatchRow);
});

it('bảng lỗ theo Nhà cung cấp hiện Giá vốn hàng lỗi và Lỗ ròng', function () {
    $this->actingAs($this->admin);

    Livewire::test(SupplierLossReportPage::class)
        ->assertCanSeeTableRecords([$this->supplierRow])
        ->assertTableColumnStateSet('supplier', 'Kinguin', $this->supplierRow)
        ->assertTableColumnStateSet('defective_loss', 90_000, $this->supplierRow)
        ->assertTableColumnStateSet('net_loss', 90_000, $this->supplierRow);
});

it('ba trang đều xuất CSV và XLSX theo khoảng ngày đang lọc', function () {
    $this->actingAs($this->admin);

    Livewire::test(ProfitReportPage::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('bao-cao-lai-lo-2026-09-01-2026-09-15.csv');

    Livewire::test(DispatchProfitReportPage::class)
        ->callAction('exportXlsx')
        ->assertFileDownloaded('bao-cao-lai-lo-phieu-xuat-2026-09-01-2026-09-15.xlsx');

    Livewire::test(SupplierLossReportPage::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('bao-cao-lo-nha-cung-cap-2026-09-01-2026-09-15.csv');
});
