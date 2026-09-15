<?php

namespace App\Inventory\Claims;

/**
 * Trạng thái Khiếu nại nhà cung cấp: Nháp → Đã gửi → Đã giải quyết; Nháp hoặc Đã gửi → Đã huỷ.
 */
enum SupplierClaimStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Sent => 'Đã gửi',
            self::Resolved => 'Đã giải quyết',
            self::Cancelled => 'Đã huỷ',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'warning',
            self::Resolved => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Chưa giải quyết, chưa huỷ.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Draft, self::Sent];
    }
}
