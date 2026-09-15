<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Dòng xuất. Giao thay chỉ thêm dòng khi giao sang Sản phẩm khác.
 */
enum DispatchLineKind: string
{
    case Sale = 'sale';
    case Additional = 'additional';
    case Corrective = 'corrective';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Giao bán',
            self::Additional => 'Giao thêm',
            self::Corrective => 'Giao thay',
        };
    }
}
