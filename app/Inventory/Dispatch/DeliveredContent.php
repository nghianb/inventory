<?php

namespace App\Inventory\Dispatch;

use Carbon\CarbonImmutable;

/**
 * Nội dung một Slot vừa giao cho màn kết quả. Dạng đầy đủ có tin nhắn đã ghép Mẫu giao hàng và
 * chỉ sống trong bộ nhớ của lần xem đã ghi Nhật ký xem mã; dạng che không có tin nhắn.
 */
final readonly class DeliveredContent
{
    public const SEPARATOR = "\n\n----------\n\n";

    /**
     * @param  array<string, string>  $fields  theo tên hiển thị Trường nội dung, đúng thứ tự trường
     * @param  ?string  $message  null khi màn kết quả chỉ hiện dạng che
     */
    public function __construct(
        public int $deliveryId,
        public string $productName,
        public int $stockUnitId,
        public int $slotId,
        public array $fields,
        public ?string $message,
        public ?CarbonImmutable $expiresOn,
        public CarbonImmutable $warrantyEndsOn,
    ) {}

    /**
     * Nội dung Copy tất cả: tin nhắn của từng Slot, có dòng phân cách.
     *
     * @param  list<DeliveredContent>  $slots
     */
    public static function copyAll(array $slots): string
    {
        return implode(self::SEPARATOR, array_map(fn (DeliveredContent $slot): string => (string) $slot->message, $slots));
    }
}
