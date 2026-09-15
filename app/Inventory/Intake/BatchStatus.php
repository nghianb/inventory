<?php

namespace App\Inventory\Intake;

/**
 * Trạng thái Lô nhập qua hai pha: kiểm tra (job) → xem trước → xác nhận. Đã xác nhận thì
 * đóng. Bản kiểm tra chưa xác nhận bị bỏ hoặc quá hạn xác nhận thì nội dung tạm bị xoá.
 */
enum BatchStatus: string
{
    case Validating = 'validating';
    case Validated = 'validated';
    case ValidationFailed = 'validation-failed';
    case Confirmed = 'confirmed';
    case Discarded = 'discarded';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Validating => 'Đang kiểm tra',
            self::Validated => 'Chờ xác nhận',
            self::ValidationFailed => 'Kiểm tra thất bại',
            self::Confirmed => 'Đã xác nhận',
            self::Discarded => 'Đã bỏ',
            self::Expired => 'Quá hạn xác nhận',
        };
    }

    /**
     * Chưa xác nhận, chưa bỏ, chưa hết hạn: còn nội dung tạm.
     *
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Validating, self::Validated, self::ValidationFailed];
    }
}
