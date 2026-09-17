<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DefectRateReportPage;
use App\Filament\Pages\DispatchFreezePage;
use App\Filament\Pages\DispatchProfitReportPage;
use App\Filament\Pages\MovementReportPage;
use App\Filament\Pages\ProfitReportPage;
use App\Filament\Pages\StockReportPage;
use App\Filament\Pages\SupplierLossReportPage;
use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductTypes\ProductTypeResource;
use App\Filament\Resources\RevealLogEntries\RevealLogEntryResource;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Resources\SecurityLogEntries\SecurityLogEntryResource;
use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\NavGroup;
use App\Inventory\Access\Role;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;

dataset('mục điều hướng', [
    'Phiếu xuất' => [DispatchResource::class, NavGroup::XuatHang],
    'Báo lỗi' => [DefectReportResource::class, NavGroup::XuatHang],
    'Kênh bán' => [SalesChannelResource::class, NavGroup::XuatHang],

    'Lô nhập' => [BatchResource::class, NavGroup::NhapHang],
    'Nhà cung cấp' => [SupplierResource::class, NavGroup::NhapHang],
    'Khiếu nại nhà cung cấp' => [SupplierClaimResource::class, NavGroup::NhapHang],

    'Đơn vị hàng' => [StockUnitResource::class, NavGroup::KhoHang],
    'Sản phẩm' => [ProductResource::class, NavGroup::KhoHang],
    'Loại sản phẩm' => [ProductTypeResource::class, NavGroup::KhoHang],

    'Tồn kho' => [StockReportPage::class, NavGroup::BaoCao],
    'Nhập/xuất' => [MovementReportPage::class, NavGroup::BaoCao],
    'Lãi/lỗ' => [ProfitReportPage::class, NavGroup::BaoCao],
    'Lãi/lỗ theo phiếu xuất' => [DispatchProfitReportPage::class, NavGroup::BaoCao],
    'Tỉ lệ lỗi' => [DefectRateReportPage::class, NavGroup::BaoCao],
    'Lỗ theo nhà cung cấp' => [SupplierLossReportPage::class, NavGroup::BaoCao],

    'Nhật ký xem mã' => [RevealLogEntryResource::class, NavGroup::NhatKy],
    'Nhật ký bảo mật' => [SecurityLogEntryResource::class, NavGroup::NhatKy],

    'Nhân viên' => [StaffResource::class, NavGroup::HeThong],
    'Khoá API' => [ApiKeyResource::class, NavGroup::HeThong],
    'Tạm dừng xuất kho' => [DispatchFreezePage::class, NavGroup::HeThong],

    // Tổng quan đứng ngoài mọi nhóm, trên cùng sidebar.
    'Tổng quan' => [Dashboard::class, null],
]);

it('xếp mỗi mục điều hướng vào đúng nhóm', function (string $item, ?NavGroup $group) {
    expect($item::getNavigationGroup())->toBe($group);
})->with('mục điều hướng');

it('xếp nhóm theo nhịp dùng, nhóm chỉ Quản trị thấy nằm đáy', function () {
    $labels = array_map(fn (NavGroup $group) => $group->getLabel(), NavGroup::cases());

    expect($labels)->toBe(['Xuất hàng', 'Nhập hàng', 'Kho hàng', 'Báo cáo', 'Nhật ký', 'Hệ thống']);
});

it('đăng ký đủ sáu nhóm vào panel, đúng thứ tự của enum', function () {
    $labels = array_map(
        fn ($group) => $group->getLabel(),
        array_values(Filament::getPanel('admin')->getNavigationGroups()),
    );

    expect($labels)->toBe(['Xuất hàng', 'Nhập hàng', 'Kho hàng', 'Báo cáo', 'Nhật ký', 'Hệ thống']);
});

it('không mục nào trùng icon với mục khác', function () {
    $panel = Filament::getPanel('admin');

    $icons = collect([...$panel->getResources(), ...$panel->getPages()])
        ->map(fn (string $item) => $item::getNavigationIcon())
        ->filter()
        ->map(fn ($icon) => $icon instanceof BackedEnum ? $icon->value : (string) $icon);

    expect($icons->duplicates()->all())->toBe([]);
});

it('rút gọn nhãn báo cáo vì nhóm đã nói chữ "Báo cáo" rồi', function () {
    expect(StockReportPage::getNavigationLabel())->toBe('Tồn kho')
        ->and(MovementReportPage::getNavigationLabel())->toBe('Nhập/xuất')
        ->and(ProfitReportPage::getNavigationLabel())->toBe('Lãi/lỗ')
        ->and(DefectRateReportPage::getNavigationLabel())->toBe('Tỉ lệ lỗi');
});

it('giữ tiêu đề trang đầy đủ để mở ra vẫn biết đang xem báo cáo gì', function () {
    // $title là protected static; đọc qua reflection để test không phải nới visibility của trang.
    $title = fn (string $page): string => (new ReflectionProperty($page, 'title'))->getValue();

    expect($title(StockReportPage::class))->toBe('Báo cáo tồn kho')
        ->and($title(MovementReportPage::class))->toBe('Báo cáo nhập/xuất')
        ->and($title(ProfitReportPage::class))->toBe('Báo cáo lãi/lỗ')
        ->and($title(DefectRateReportPage::class))->toBe('Báo cáo tỉ lệ lỗi theo nhà cung cấp')
        ->and(StockReportPage::getNavigationLabel())->not->toBe($title(StockReportPage::class));
});

it('đặt nhãn tiếng Việt cho Tổng quan', function () {
    expect(Dashboard::getNavigationLabel())->toBe('Tổng quan');
});

/**
 * Dựng menu thật của panel thay vì đọc $navigationSort: assert tĩnh từng đọc là đủ, nhưng nó bỏ lọt
 * đúng chuyện thứ tự mục chưa được khai bao giờ.
 */
it('xếp thứ tự mục trong từng nhóm, Quản trị thấy đủ 21 mục', function () {
    $this->seed(RoleSeeder::class);
    $this->actingAs(staffMember(Role::Owner));

    $menu = collect(Filament::getNavigation())
        ->filter(fn ($group) => filled($group->getLabel()))
        ->mapWithKeys(fn ($group) => [
            $group->getLabel() => collect($group->getItems())
                ->map(fn ($item) => $item->getLabel())
                ->values()
                ->all(),
        ])
        ->all();

    expect($menu)->toBe([
        'Xuất hàng' => ['Phiếu xuất', 'Báo lỗi', 'Kênh bán'],
        'Nhập hàng' => ['Lô nhập', 'Nhà cung cấp', 'Khiếu nại nhà cung cấp'],
        'Kho hàng' => ['Đơn vị hàng', 'Sản phẩm', 'Loại sản phẩm'],
        'Báo cáo' => ['Tồn kho', 'Nhập/xuất', 'Lãi/lỗ', 'Lãi/lỗ theo phiếu xuất', 'Tỉ lệ lỗi', 'Lỗ theo nhà cung cấp'],
        'Nhật ký' => ['Nhật ký xem mã', 'Nhật ký bảo mật'],
        'Hệ thống' => ['Nhân viên', 'Khoá API', 'Tạm dừng xuất kho'],
    ]);
});
