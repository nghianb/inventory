<?php

use App\Filament\Pages\DefectRateReportPage;
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

    $this->admin = staffMember(Role::QuanTri);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);

    $catalog = app(ProductCatalog::class);
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
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam 100K',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));

    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));

    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00'));
    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [
            new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a"),
            new BatchLineDraft($this->steam, 100_000, 'STEAM-1'),
        ],
    )));

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-1',
        [new DispatchLineDraft($this->netflix, 1, 500_000)],
    ));
    app(StockDefect::class)->markDefective($this->admin, StockUnit::where('content->username', 'a@shop.test')->sole(), 'Nhà cung cấp thu hồi');

    $this->netflixRow = "nha-cung-cap-{$this->kinguin->id}-san-pham-{$this->netflix->id}";
    $this->steamRow = "nha-cung-cap-{$this->kinguin->id}-san-pham-{$this->steam->id}";
    $this->totalRow = "nha-cung-cap-{$this->kinguin->id}-tong";
});

it('chỉ Quản trị và Nhập kho vào được báo cáo Tỉ lệ lỗi', function (?Role $role, bool $sees) {
    $this->actingAs($role === null ? User::factory()->withTwoFactor()->create() : staffMember($role));

    $this->get(DefectRateReportPage::getUrl())->assertStatus($sees ? 200 : 403);
})->with([
    'Quản trị' => [Role::QuanTri, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, false],
    'không vai trò' => [null, false],
]);

it('bảng hiện số liệu của DefectRateReport, mỗi Nhà cung cấp kết thúc bằng dòng tổng', function () {
    $this->actingAs($this->stocker);

    Livewire::test(DefectRateReportPage::class)
        ->assertCanSeeTableRecords([$this->netflixRow, $this->steamRow, $this->totalRow], inOrder: true)
        ->assertTableColumnStateSet('delivered_units', 1, $this->netflixRow)
        ->assertTableColumnStateSet('defective_units', 1, $this->netflixRow)
        ->assertTableColumnStateSet('defect_rate', '100,0%', $this->netflixRow)
        // Mã Steam chưa giao Slot nào: chưa có gì để so sánh.
        ->assertTableColumnStateSet('defect_rate', '', $this->steamRow)
        ->assertTableColumnStateSet('code', 'Tổng', $this->totalRow)
        ->assertTableColumnStateSet('intake_units', 2, $this->totalRow)
        ->assertTableColumnStateSet('defect_rate', '100,0%', $this->totalRow);
});

it('lọc khoảng ngày nhập và Sản phẩm', function () {
    $this->actingAs($this->admin);

    Livewire::test(DefectRateReportPage::class)
        // Kỳ chưa có lứa nhập nào.
        ->filterTable('range', ['from' => '2026-08-01', 'to' => '2026-08-31'])
        ->assertCanNotSeeTableRecords([$this->netflixRow, $this->totalRow])
        ->filterTable('range', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->filterTable('products', [$this->steam->id])
        ->assertCanSeeTableRecords([$this->steamRow])
        ->assertCanNotSeeTableRecords([$this->netflixRow]);
});

it('xuất CSV và XLSX tải file theo khoảng ngày đang lọc', function () {
    $this->actingAs($this->stocker);

    Livewire::test(DefectRateReportPage::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('bao-cao-ti-le-loi-2026-09-01-2026-09-15.csv')
        ->callAction('exportXlsx')
        ->assertFileDownloaded('bao-cao-ti-le-loi-2026-09-01-2026-09-15.xlsx');
});
