<?php

use App\Filament\Pages\StockReportPage;
use App\Filament\Widgets\StockAlerts;
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
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('intake');
    $this->seed(RoleSeeder::class);
    app(KeyFingerprints::class)->register();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

    $this->admin = staffMember(Role::Owner);
    $this->stocker = staffMember(Role::NhapKho);
    $this->seller = staffMember(Role::BanHang);

    $catalog = app(ProductCatalog::class);
    // Netflix: 3 Slot Tồn bán được, Ngưỡng sắp hết 5.
    $this->netflix = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::Account,
        name: 'Netflix 1 tháng',
        code: 'NETFLIX-1M',
        fields: [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        defaultSlots: 3,
        lowStockThreshold: 5,
    ));
    // Steam: không có ngưỡng, một mã hết hạn trong 3 ngày.
    $this->steam = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Steam 100K',
        code: 'STEAM-100K',
        fields: [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
    ));
    // Spotify: không có hàng, không có ngưỡng: không cảnh báo.
    $this->spotify = $catalog->create($this->admin, new ProductDraft(
        type: ProductType::OneTimeCode,
        name: 'Spotify 1 tháng',
        code: 'SPOTIFY-1M',
        fields: [new ContentFieldDraft('code', 'Mã', dedupeKey: true)],
    ));

    $intake = app(BatchIntake::class);
    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [
            new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a"),
            new BatchLineDraft($this->steam, 100_000, 'STEAM-1', expiry: ExpiryRule::on(CarbonImmutable::today()->addDays(3))),
        ],
    )));
});

it('cả ba vai trò vào được báo cáo Tồn kho và thấy widget cảnh báo; nhân viên không có vai trò thì không', function (?Role $role, bool $sees) {
    $this->actingAs($role === null ? User::factory()->withTwoFactor()->create() : staffMember($role));

    $this->get(StockReportPage::getUrl())->assertStatus($sees ? 200 : 403);
    expect(StockAlerts::canView())->toBe($sees);
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, true],
    'không vai trò' => [null, false],
]);

it('Bán hàng không thấy cột giá trị và bộ lọc Nhà cung cấp', function (Role $role, bool $values) {
    $this->actingAs(staffMember($role));

    $page = Livewire::test(StockReportPage::class)
        ->assertCanSeeTableRecords([$this->netflix, $this->steam, $this->spotify]);

    foreach (['stock_value', 'expiring_cost'] as $column) {
        $values ? $page->assertTableColumnVisible($column) : $page->assertTableColumnHidden($column);
    }

    $values ? $page->assertTableFilterVisible('supplier') : $page->assertTableFilterHidden('supplier');

    $alerts = Livewire::test(StockAlerts::class);
    $values ? $alerts->assertTableColumnVisible('expiring_cost') : $alerts->assertTableColumnHidden('expiring_cost');
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, false],
]);

it('bảng hiện số liệu của StockReport; lọc sắp hết, Hết hạn trong N ngày, Sản phẩm', function () {
    $this->actingAs($this->stocker);

    Livewire::test(StockReportPage::class)
        ->assertTableColumnStateSet('sellable_slots', 3, $this->netflix)
        ->assertTableColumnStateSet('stock_value', 90_000, $this->netflix)
        ->assertTableColumnStateSet('expiring_slots', 1, $this->steam)
        ->filterTable('low_stock')
        ->assertCanSeeTableRecords([$this->netflix])
        ->assertCanNotSeeTableRecords([$this->steam, $this->spotify])
        ->removeTableFilters()
        ->filterTable('expiring', ['days' => 2, 'only' => true])
        ->assertCountTableRecords(0)
        ->filterTable('expiring', ['days' => 3, 'only' => true])
        ->assertCanSeeTableRecords([$this->steam])
        ->assertCanNotSeeTableRecords([$this->netflix, $this->spotify])
        ->removeTableFilters()
        ->filterTable('products', [$this->spotify->id])
        ->assertCanSeeTableRecords([$this->spotify])
        ->assertCanNotSeeTableRecords([$this->netflix, $this->steam]);
});

it('Bán hàng mở URL có bộ lọc Nhà cung cấp thì bộ lọc bị bỏ qua', function () {
    $this->actingAs($this->seller);
    $g2a = app(SupplierDirectory::class)->create($this->admin, 'G2A');

    Livewire::withQueryParams(['filters' => ['supplier' => ['value' => $g2a->id]]])
        ->test(StockReportPage::class)
        ->assertCanSeeTableRecords([$this->netflix, $this->steam]);
});

it('widget cảnh báo liệt kê Sản phẩm sắp hết hoặc có hàng hết hạn trong 7 ngày và mở báo cáo đã lọc', function () {
    $this->actingAs($this->seller);

    Livewire::test(StockAlerts::class)
        ->assertCanSeeTableRecords([$this->netflix, $this->steam])
        ->assertCanNotSeeTableRecords([$this->spotify]);

    Livewire::withQueryParams(['filters' => ['low_stock' => ['isActive' => true]]])
        ->test(StockReportPage::class)
        ->assertCanSeeTableRecords([$this->netflix])
        ->assertCanNotSeeTableRecords([$this->steam, $this->spotify]);

    // Widget của dashboard tải lười nên không kiểm qua HTML trang.
    expect(Filament::getPanel('admin')->getWidgets())->toContain(StockAlerts::class);
});

it('xuất CSV và XLSX tải file theo ngày', function () {
    $this->actingAs($this->seller);

    Livewire::test(StockReportPage::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('bao-cao-ton-kho-2026-09-15.csv')
        ->callAction('exportXlsx')
        ->assertFileDownloaded('bao-cao-ton-kho-2026-09-15.xlsx');
});
