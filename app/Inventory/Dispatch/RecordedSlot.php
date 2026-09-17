<?php

namespace App\Inventory\Dispatch;

use Carbon\CarbonImmutable;

/**
 * Một Slot Còn hàng khớp Khoá chống trùng mà Quản trị đang tra, để chọn đích danh cho Ghi nhận giao
 * bù. Nội dung chỉ ở dạng che: chọn Slot không phải là xem mã.
 */
final readonly class RecordedSlot
{
    public function __construct(
        public int $slotId,
        public int $stockUnitId,
        public string $productName,
        public string $maskedContent,
        public ?CarbonImmutable $expiresOn,
    ) {}

    /**
     * Dạng chữ để chọn trong danh sách: "Netflix 1 tháng · #12 · Slot #34 · Tên đăng nhập: a@shop.test".
     */
    public function label(): string
    {
        return sprintf('%s · #%d · Slot #%d · %s', $this->productName, $this->stockUnitId, $this->slotId, $this->maskedContent);
    }
}
