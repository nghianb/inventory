<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Dòng xuất. Giao thay chỉ thêm dòng khi giao sang Sản phẩm khác; mỗi Đổi hàng thêm một dòng
 * không có Giá bán, để Chi phí đổi hàng không vào Lãi gộp.
 */
enum DispatchLineKind: string
{
    case Sale = 'sale';
    case Additional = 'additional';
    case Corrective = 'corrective';
    case Replacement = 'replacement';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Giao bán',
            self::Additional => 'Giao thêm',
            self::Corrective => 'Giao thay',
            self::Replacement => 'Đổi hàng',
        };
    }
}
