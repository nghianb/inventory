<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Kênh bán: nhân viên tạo Phiếu xuất trong panel, hay website gọi vào kho bằng Khoá API.
 * Chỉ kênh loại API mới có Khoá API, hạn Giữ hàng và cờ bắt buộc Giá bán.
 */
enum SalesChannelType: string
{
    case Manual = 'manual';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Thủ công',
            self::Api => 'API',
        };
    }
}
