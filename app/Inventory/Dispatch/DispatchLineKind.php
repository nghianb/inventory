<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Dòng xuất. Giao thêm và Giao thay thêm loại của chúng.
 */
enum DispatchLineKind: string
{
    case Sale = 'sale';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Giao bán',
        };
    }
}
