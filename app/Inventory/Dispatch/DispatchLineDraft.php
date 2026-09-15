<?php

namespace App\Inventory\Dispatch;

use App\Models\Product;

/**
 * Một Dòng xuất trên form: Sản phẩm, số Slot cần giao, Giá bán tổng dòng tuỳ chọn.
 */
final readonly class DispatchLineDraft
{
    public function __construct(
        public ?Product $product,
        public int $quantity,
        public ?int $salePrice = null,
    ) {}
}
