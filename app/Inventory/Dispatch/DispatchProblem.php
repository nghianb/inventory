<?php

namespace App\Inventory\Dispatch;

use App\Models\Product;

/**
 * Một lỗi kiểm tra của Phiếu xuất. Mã đơn trùng kèm phiếu đã chiếm mã để nhân viên mở ra xem.
 */
final readonly class DispatchProblem
{
    public function __construct(
        public string $message,
        public ?int $existingDispatchId = null,
    ) {}

    public static function discontinued(Product $product): self
    {
        return new self("Sản phẩm \"{$product->name}\" đã Ngừng bán.");
    }
}
