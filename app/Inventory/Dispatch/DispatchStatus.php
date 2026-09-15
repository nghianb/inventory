<?php

namespace App\Inventory\Dispatch;

/**
 * Trạng thái Phiếu xuất. Xuất kho thủ công giữ và giao trong một bước nên phiếu tạo ra đã
 * Hoàn tất; Đang giữ, Hết hạn giữ và Đã huỷ dùng cho kênh API.
 */
enum DispatchStatus: string
{
    case Holding = 'holding';
    case HoldExpired = 'hold-expired';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Holding => 'Đang giữ',
            self::HoldExpired => 'Hết hạn giữ',
            self::Completed => 'Hoàn tất',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Holding => 'warning',
            self::HoldExpired, self::Cancelled => 'gray',
            self::Completed => 'success',
        };
    }
}
