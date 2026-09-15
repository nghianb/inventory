<?php

namespace App\Inventory\Claims;

/**
 * Kết quả của một Đơn vị hàng khi Khiếu nại nhà cung cấp được giải quyết.
 */
enum ClaimOutcome: string
{
    /** Nhà cung cấp trả tiền, kèm số tiền và ngày. */
    case Refund = 'refund';

    /** Nhà cung cấp gửi hàng thay thế, vào kho bằng Lô nhập liên kết với khiếu nại, Giá vốn 0. */
    case ReplacementGoods = 'replacement-goods';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Bồi hoàn tiền',
            self::ReplacementGoods => 'Hàng thay thế',
            self::Rejected => 'Bị từ chối',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Refund, self::ReplacementGoods => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $outcome): array => [$outcome->value => $outcome->label()])->all();
    }
}
