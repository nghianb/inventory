<?php

namespace App\Inventory\Intake;

/**
 * Trạng thái Lô nhập qua hai pha: kiểm tra (job) → xem trước → xác nhận. Đã xác nhận thì đóng.
 */
enum BatchStatus: string
{
    case Validating = 'validating';
    case Validated = 'validated';
    case ValidationFailed = 'validation-failed';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Validating => 'Đang kiểm tra',
            self::Validated => 'Chờ xác nhận',
            self::ValidationFailed => 'Kiểm tra thất bại',
            self::Confirmed => 'Đã xác nhận',
        };
    }
}
