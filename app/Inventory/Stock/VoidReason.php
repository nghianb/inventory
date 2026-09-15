<?php

namespace App\Inventory\Stock;

/**
 * Lý do Huỷ hàng: chỉ lý do không phải lỗi hàng. Hàng hỏng hoặc bị nhà cung cấp thu hồi dùng
 * Đánh dấu Lỗi.
 */
enum VoidReason: string
{
    case WrongDelivery = 'wrong-delivery';
    case ContentExposed = 'content-exposed';
    case DiscontinuedLot = 'discontinued-lot';

    public function label(): string
    {
        return match ($this) {
            self::WrongDelivery => 'Giao nhầm',
            self::ContentExposed => 'Lộ nội dung',
            self::DiscontinuedLot => 'Ngừng kinh doanh lô',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
