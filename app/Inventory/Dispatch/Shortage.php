<?php

namespace App\Inventory\Dispatch;

/**
 * Một Dòng xuất không đủ hàng: cần bao nhiêu Slot, giao được bao nhiêu lúc này. Mang cả Mã sản phẩm
 * để API trả lỗi hết hàng theo đúng thứ website tham chiếu, không phải tra ngược lại bảng Sản phẩm.
 */
final readonly class Shortage
{
    public function __construct(
        public int $productId,
        public string $productCode,
        public string $productName,
        public int $needed,
        public int $available,
    ) {}
}
