<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Kênh bán. Kênh loại API (website gọi vào bằng Khoá API) thêm cùng API xuất kho.
 */
enum SalesChannelType: string
{
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Thủ công',
        };
    }
}
