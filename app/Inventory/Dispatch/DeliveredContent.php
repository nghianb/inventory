<?php

namespace App\Inventory\Dispatch;

/**
 * Nội dung đầy đủ của một Slot vừa giao, đã ghép Mẫu giao hàng, cho màn kết quả. Chỉ sống
 * trong bộ nhớ của lần xem đã ghi Nhật ký xem mã.
 */
final readonly class DeliveredContent
{
    public const SEPARATOR = "\n\n----------\n\n";

    /**
     * @param  array<string, string>  $fields  theo tên hiển thị Trường nội dung, đúng thứ tự trường
     */
    public function __construct(
        public int $deliveryId,
        public string $productName,
        public int $stockUnitId,
        public int $slotId,
        public array $fields,
        public string $message,
    ) {}

    /**
     * Mẫu giao hàng mặc định: mỗi Trường nội dung một dòng "Tên trường: giá trị".
     *
     * @param  array<string, string>  $fields
     */
    public static function defaultMessage(array $fields): string
    {
        return implode("\n", array_map(fn (string $label, string $value): string => "{$label}: {$value}", array_keys($fields), $fields));
    }

    /**
     * Nội dung Copy tất cả: tin nhắn của từng Slot, có dòng phân cách.
     *
     * @param  list<DeliveredContent>  $slots
     */
    public static function copyAll(array $slots): string
    {
        return implode(self::SEPARATOR, array_map(fn (DeliveredContent $slot): string => $slot->message, $slots));
    }
}
