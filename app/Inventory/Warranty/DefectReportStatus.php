<?php

namespace App\Inventory\Warranty;

/**
 * Trạng thái Báo lỗi: Chờ xác minh → Xác nhận (kèm Phạm vi lỗi) hoặc Bác bỏ.
 */
enum DefectReportStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ xác minh',
            self::Confirmed => 'Xác nhận',
            self::Rejected => 'Bác bỏ',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'danger',
            self::Rejected => 'gray',
        };
    }
}
