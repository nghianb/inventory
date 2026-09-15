<?php

namespace App\Inventory\Stock;

/**
 * Mức Tồn bán được của một Sản phẩm, cho badge khi chọn Sản phẩm xuất kho.
 */
enum StockLevel: string
{
    case Ok = 'ok';
    // Không vượt Ngưỡng sắp hết.
    case Low = 'low';
    case Empty = 'empty';

    public static function of(int $sellable, ?int $lowStockThreshold): self
    {
        return match (true) {
            $sellable <= 0 => self::Empty,
            $lowStockThreshold !== null && $sellable <= $lowStockThreshold => self::Low,
            default => self::Ok,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Low => 'warning',
            self::Empty => 'danger',
        };
    }
}
