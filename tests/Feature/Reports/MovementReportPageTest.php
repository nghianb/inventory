<?php

use App\Filament\Pages\MovementReportPage;
use App\Inventory\Access\Role;
use App\Inventory\Catalog\ContentFieldDraft;
use App\Inventory\Catalog\ContentFieldType;
use App\Inventory\Catalog\StockForm;
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
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
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

    $this->netflix = productOf(
        StockForm::Account,
        [
            new ContentFieldDraft('username', 'Tên đăng nhập', ContentFieldType::Email, sensitive: false, dedupeKey: true),
            new ContentFieldDraft('password', 'Mật khẩu'),
        ],
        'Netflix 1 tháng',
        'NETFLIX-1M',
        defaultSlots: 3,
    );
    $this->steam = productOf(
        StockForm::OneTimeCode,
        [new ContentFieldDraft('code', 'Mã thẻ', dedupeKey: true)],
        'Steam 100K',
        'STEAM-100K',
    );

    $this->kinguin = app(SupplierDirectory::class)->create($this->admin, 'Kinguin');
    $this->shopee = app(SalesChannelDirectory::class)->create($this->admin, new SalesChannelDraft('Shopee'));

    $intake = app(BatchIntake::class);
    $intake->confirm($this->admin, $intake->submit($this->admin, new BatchDraft(
        supplier: $this->kinguin,
        receivedOn: CarbonImmutable::today(),
        lines: [
            new BatchLineDraft($this->netflix, 90_000, "a@shop.test\tpw-a"),
            new BatchLineDraft($this->steam, 100_000, 'STEAM-1'),
        ],
    )));

    app(ManualDispatch::class)->create($this->seller, new DispatchDraft(
        $this->shopee,
        'SP-1',
        [new DispatchLineDraft($this->netflix, 1, 500_000)],
    ));
});

it('cả ba vai trò vào được báo cáo Nhập/xuất; nhân viên không có vai trò thì không', function (?Role $role, bool $sees) {
    $this->actingAs($role === null ? User::factory()->withTwoFactor()->create() : staffMember($role));

    $this->get(MovementReportPage::getUrl())->assertStatus($sees ? 200 : 403);
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, true],
    'không vai trò' => [null, false],
]);

it('Bán hàng không thấy cột Giá vốn, Giá bán và bộ lọc Nhà cung cấp', function (Role $role, bool $values) {
    $this->actingAs(staffMember($role));

    $page = Livewire::test(MovementReportPage::class)
        ->assertCanSeeTableRecords([$this->netflix, $this->steam]);

    foreach (['in_cost', 'out_cost', 'sale_total'] as $column) {
        $values ? $page->assertTableColumnVisible($column) : $page->assertTableColumnHidden($column);
    }

    $values ? $page->assertTableFilterVisible('supplier') : $page->assertTableFilterHidden('supplier');
})->with([
    'Quản trị' => [Role::Owner, true],
    'Nhập kho' => [Role::NhapKho, true],
    'Bán hàng' => [Role::BanHang, false],
]);

it('bảng hiện số liệu của MovementReport; lọc khoảng ngày và Sản phẩm', function () {
    $this->actingAs($this->stocker);

    Livewire::test(MovementReportPage::class)
        ->assertCanSeeTableRecords([$this->netflix, $this->steam])
        ->assertTableColumnStateSet('in_slots', 3, $this->netflix)
        ->assertTableColumnStateSet('sold_slots', 1, $this->netflix)
        ->assertTableColumnStateSet('sale_total', 500_000, $this->netflix)
        ->assertTableColumnStateSet('out_cost', 30_000, $this->netflix)
        // Kỳ chưa có biến động nào.
        ->filterTable('range', ['from' => '2026-08-01', 'to' => '2026-08-31'])
        ->assertCountTableRecords(0)
        ->filterTable('range', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->filterTable('products', [$this->steam->id])
        ->assertCanSeeTableRecords([$this->steam])
        ->assertCanNotSeeTableRecords([$this->netflix]);
});

it('lọc Kênh bán chỉ còn phần Xuất và ẩn cột Nhập, Huỷ hàng, Chuyển Tồn lỗi', function () {
    $this->actingAs($this->stocker);

    Livewire::test(MovementReportPage::class)
        ->assertTableColumnVisible('in_slots')
        ->filterTable('channel', $this->shopee->id)
        ->assertCanSeeTableRecords([$this->netflix])
        ->assertCanNotSeeTableRecords([$this->steam])
        ->assertTableColumnHidden('in_slots')
        ->assertTableColumnHidden('voided_slots')
        ->assertTableColumnHidden('defective_slots');
});

it('Bán hàng mở URL có bộ lọc Nhà cung cấp thì bộ lọc bị bỏ qua', function () {
    $this->actingAs($this->seller);
    $g2a = app(SupplierDirectory::class)->create($this->admin, 'G2A');

    Livewire::withQueryParams(['filters' => ['supplier' => ['value' => $g2a->id]]])
        ->test(MovementReportPage::class)
        ->assertCanSeeTableRecords([$this->netflix, $this->steam]);
});

it('xuất CSV và XLSX tải file theo khoảng ngày đang lọc', function () {
    $this->actingAs($this->seller);

    Livewire::test(MovementReportPage::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('bao-cao-nhap-xuat-2026-09-01-2026-09-15.csv')
        ->callAction('exportXlsx')
        ->assertFileDownloaded('bao-cao-nhap-xuat-2026-09-01-2026-09-15.xlsx');
});
