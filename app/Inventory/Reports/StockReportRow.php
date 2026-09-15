<?php

namespace App\Inventory\Reports;

use App\Models\Product;
use InvalidArgumentException;

/**
 * Một dòng báo cáo Tồn kho: một Sản phẩm, đếm theo Slot.
 */
final readonly class StockReportRow
{
    /**
     * @param  int  $sellable  Tồn bán được
     * @param  int  $reserved  Slot Đã giữ
     * @param  int  $paused  Slot tạm ngừng vì Báo lỗi Chờ xác minh
     * @param  int  $belowMinRemaining  Slot chưa quá Hạn sử dụng nhưng không đạt Hạn còn lại tối thiểu
     * @param  int  $defective  Tồn lỗi
     * @param  int  $stockUnits  số Đơn vị hàng có Slot tồn
     * @param  int  $stockValue  tổng Giá vốn Slot tồn
     * @param  int  $expiringSlots  Slot Còn hàng của Đơn vị hàng Hoạt động hết hạn trong N ngày
     * @param  int  $expiringCost  Giá vốn sắp mất của các Slot đó
     */
    public function __construct(
        public int $productId,
        public string $code,
        public string $name,
        public bool $discontinued,
        public ?int $lowStockThreshold,
        public int $sellable,
        public int $reserved,
        public int $paused,
        public int $belowMinRemaining,
        public int $defective,
        public int $stockUnits,
        public int $stockValue,
        public int $expiringSlots,
        public int $expiringCost,
        public bool $lowStock,
    ) {}

    /**
     * Từ một Sản phẩm lấy qua {@see StockReport::query()}.
     */
    public static function fromProduct(Product $product): self
    {
        $count = fn (string $column): int => (int) $product->getAttribute($column);

        return new self(
            productId: (int) $product->getKey(),
            code: $product->code,
            name: $product->name,
            discontinued: $product->isDiscontinued(),
            lowStockThreshold: $product->low_stock_threshold,
            sellable: $count('sellable_slots'),
            reserved: $count('reserved_slots'),
            paused: $count('paused_slots'),
            belowMinRemaining: $count('below_min_slots'),
            defective: $count('defective_slots'),
            stockUnits: $count('stock_unit_count'),
            stockValue: $count('stock_value'),
            expiringSlots: $count('expiring_slots'),
            expiringCost: $count('expiring_cost'),
            lowStock: (bool) $product->getAttribute('low_stock'),
        );
    }

    /**
     * Giá trị theo khoá cột của {@see StockReport::columns()}.
     */
    public function cell(string $column): string|int|null
    {
        return match ($column) {
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->discontinued ? 'Ngừng bán' : 'Đang bán',
            'sellable_slots' => $this->sellable,
            'low_stock_threshold' => $this->lowStockThreshold,
            'low_stock' => $this->lowStock ? 'Có' : 'Không',
            'reserved_slots' => $this->reserved,
            'paused_slots' => $this->paused,
            'below_min_slots' => $this->belowMinRemaining,
            'defective_slots' => $this->defective,
            'stock_unit_count' => $this->stockUnits,
            'stock_value' => $this->stockValue,
            'expiring_slots' => $this->expiringSlots,
            'expiring_cost' => $this->expiringCost,
            default => throw new InvalidArgumentException("Báo cáo Tồn kho không có cột {$column}."),
        };
    }
}
