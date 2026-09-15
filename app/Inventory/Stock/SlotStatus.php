<?php

namespace App\Inventory\Stock;

/**
 * Trạng thái Slot. Đã giao không bao giờ quay về Còn hàng.
 */
enum SlotStatus: string
{
    case InStock = 'in-stock';
    case Reserved = 'reserved';
    case Delivered = 'delivered';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Còn hàng',
            self::Reserved => 'Đã giữ',
            self::Delivered => 'Đã giao',
            self::Voided => 'Đã huỷ',
        };
    }
}
