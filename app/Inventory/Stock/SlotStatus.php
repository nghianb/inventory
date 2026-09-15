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
    // Huỷ nhập: coi như chưa từng vào kho, không phải tồn, không phải tổn thất.
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Còn hàng',
            self::Reserved => 'Đã giữ',
            self::Delivered => 'Đã giao',
            self::Voided => 'Đã huỷ',
            self::Reversed => 'Đã huỷ nhập',
        };
    }
}
