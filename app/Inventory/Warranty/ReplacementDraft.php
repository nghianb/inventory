<?php

namespace App\Inventory\Warranty;

use App\Models\Product;

/**
 * Yêu cầu Đổi hàng cho một Báo lỗi Chờ đổi.
 */
final readonly class ReplacementDraft
{
    /**
     * @param  ?Product  $product  Sản phẩm giao ra; null là cùng Sản phẩm với Đơn vị hàng lỗi
     * @param  ?string  $productChangeReason  bắt buộc khi đổi sang Sản phẩm khác
     * @param  bool  $acceptShorterExpiry  chấp nhận Slot có Hạn sử dụng ngắn hơn Hạn bảo hành kế thừa
     */
    public function __construct(
        public ?Product $product = null,
        public ?string $productChangeReason = null,
        public bool $acceptShorterExpiry = false,
    ) {}
}
