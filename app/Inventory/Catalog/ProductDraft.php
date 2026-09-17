<?php

namespace App\Inventory\Catalog;

use App\Models\ProductType;

/**
 * Toàn bộ cấu hình riêng của một Sản phẩm khi tạo hoặc sửa. Dạng hàng, Trường nội dung và
 * chuẩn hoá Khoá chống trùng không nằm ở đây: chúng thuộc Loại sản phẩm.
 */
final readonly class ProductDraft
{
    public function __construct(
        public ProductType $productType,
        public string $name,
        public string $code,
        public int $defaultSlots = 1,
        public int $warrantyDays = 0,
        public int $minRemainingDays = 0,
        public ?int $lowStockThreshold = null,
        public ?string $deliveryTemplate = null,
    ) {}
}
