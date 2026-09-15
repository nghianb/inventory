<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Dòng xuất. Giao thay thêm loại của nó.
 */
enum DispatchLineKind: string
{
    case Sale = 'sale';
    case Additional = 'additional';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Giao bán',
            self::Additional => 'Giao thêm',
        };
    }
}
