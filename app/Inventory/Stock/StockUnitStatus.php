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
    // Huỷ nhập: đã nhả Khoá chống trùng, bản ghi giữ lại.
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Hoạt động',
            self::Defective => 'Lỗi',
            self::Voided => 'Đã huỷ',
            self::Reversed => 'Đã huỷ nhập',
        };
    }
}
