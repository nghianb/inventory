<?php

namespace App\Inventory\Reports;

/**
 * Bộ lọc báo cáo Tồn kho. Các bộ lọc cộng dồn (và).
 */
final readonly class StockReportFilter
{
    public const DEFAULT_EXPIRING_DAYS = 7;

    public const MAX_EXPIRING_DAYS = 3650;

    /**
     * @param  list<int>  $productIds  rỗng thì mọi Sản phẩm
     * @param  ?int  $supplierId  chỉ đếm hàng của Nhà cung cấp này; chỉ Quản trị và Nhập kho
     * @param  bool  $lowStockOnly  chỉ Sản phẩm sắp hết
     * @param  int  $expiringWithinDays  N của cột Hết hạn trong N ngày
     * @param  bool  $expiringOnly  chỉ Sản phẩm có Slot hết hạn trong N ngày
     * @param  bool  $alertsOnly  chỉ Sản phẩm sắp hết hoặc có Slot hết hạn trong N ngày (widget cảnh báo)
     */
    public function __construct(
        public array $productIds = [],
        public ?int $supplierId = null,
        public bool $lowStockOnly = false,
        public int $expiringWithinDays = self::DEFAULT_EXPIRING_DAYS,
        public bool $expiringOnly = false,
        public bool $alertsOnly = false,
    ) {}
}
