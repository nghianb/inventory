<?php

namespace App\Inventory\Warranty;

/**
 * Kết quả xử lý của Báo lỗi đã Xác nhận: Chờ đổi → Đã đổi (qua Đổi hàng) hoặc Không đổi (kèm lý do).
 */
enum DefectResolution: string
{
    case AwaitingReplacement = 'awaiting';
    case Replaced = 'replaced';
    case NotReplaced = 'not-replaced';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingReplacement => 'Chờ đổi',
            self::Replaced => 'Đã đổi',
            self::NotReplaced => 'Không đổi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingReplacement => 'warning',
            self::Replaced => 'success',
            self::NotReplaced => 'gray',
        };
    }
}
