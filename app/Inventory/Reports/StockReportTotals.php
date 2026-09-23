<?php

namespace App\Inventory\Reports;

/**
 * Tổng của cả báo cáo Tồn kho, cho ô số trên trang Tổng quan: ba con số mà mọi Vai trò xem được.
 *
 * Cố ý không mang Giá trị tồn hay Giá vốn Tồn lỗi: {@see StockReport::seesCost()} chỉ mở cho Nhập
 * kho, mà một ô hiện/ẩn theo người xem làm bố cục widget co giãn theo Vai trò.
 */
final readonly class StockReportTotals
{
    /**
     * @param  int  $sellableSlots  tổng Tồn bán được
     * @param  int  $defectiveSlots  tổng Tồn lỗi
     * @param  int  $lowStockProducts  số Sản phẩm đang bị cảnh báo sắp hết
     */
    public function __construct(
        public int $sellableSlots,
        public int $defectiveSlots,
        public int $lowStockProducts,
    ) {}
}
