<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\StockReportPage;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Reports\StockReport;
use App\Inventory\Reports\StockReportFilter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tồn kho gọn trong ba con số, trên trang Tổng quan. Không nằm trong {@see OutboundQueue} hay
 * {@see IntakeQueue} được vì cả ba Vai trò đều xem tồn kho, còn hai widget kia mỗi cái của một công
 * việc.
 *
 * Không ô tiền nào: {@see StockReport::seesCost()} chỉ mở cho Nhập kho, mà một ô hiện/ẩn theo người
 * xem làm bố cục co giãn theo Vai trò. Tồn bán được và Tồn lỗi đứng cạnh nhau là có chủ ý — CONTEXT.md
 * tách hai khái niệm này, để cạnh nhau là thấy ngay hàng nào bán được và hàng nào chỉ còn nằm đó.
 */
class StockSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 30;

    protected ?string $heading = 'Tồn kho';

    public static function canView(): bool
    {
        return app(StockReport::class)->canView(InventoryAction::actor());
    }

    public function mount(): void
    {
        abort_unless(self::canView(), 403);
    }

    protected function getStats(): array
    {
        $totals = app(StockReport::class)->totals(InventoryAction::actor(), new StockReportFilter);

        return [
            // Kho rỗng là tin xấu, không phải "sạch việc" như ô của hàng đợi: 0 Slot mà tô xanh thì
            // widget đang mừng cho một cái kho không bán được gì.
            Stat::make('Tồn bán được', DispatchResource::count($totals->sellableSlots))
                ->description($totals->sellableSlots === 0 ? 'Không còn Slot nào giao được' : 'Slot giao được ngay')
                ->color($totals->sellableSlots === 0 ? 'danger' : 'success')
                ->url(StockReportPage::getUrl()),
            Stat::make('Tồn lỗi', DispatchResource::count($totals->defectiveSlots))
                ->description($totals->defectiveSlots === 0 ? 'Không có hàng Lỗi trong kho' : 'Còn trong kho nhưng không bán được')
                ->color($totals->defectiveSlots === 0 ? 'gray' : 'danger')
                ->url(StockReportPage::getUrl(['filters' => ['defective' => ['isActive' => true]]])),
            Stat::make('Sản phẩm sắp hết', DispatchResource::count($totals->lowStockProducts))
                ->description($totals->lowStockProducts === 0 ? 'Không Sản phẩm nào chạm ngưỡng' : 'Tồn bán được không vượt Ngưỡng sắp hết')
                ->color($totals->lowStockProducts === 0 ? 'gray' : 'warning')
                ->url(StockReportPage::getUrl(['filters' => ['low_stock' => ['isActive' => true]]])),
        ];
    }
}
