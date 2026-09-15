<?php

namespace App\Inventory\Stock;

/**
 * Trạng thái Đơn vị hàng.
 */
enum StockUnitStatus: string
{
    case Active = 'active';
    case Defective = 'defective';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Hoạt động',
            self::Defective => 'Lỗi',
            self::Voided => 'Đã huỷ',
        };
    }
}
