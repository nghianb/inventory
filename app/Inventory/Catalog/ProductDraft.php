<?php

namespace App\Inventory\Catalog;

use App\Inventory\Encryption\Normalization;

/**
 * Toàn bộ cấu hình của một Sản phẩm khi tạo hoặc sửa.
 */
final readonly class ProductDraft
{
    /**
     * @param  list<ContentFieldDraft>  $fields  theo thứ tự hiển thị
     */
    public function __construct(
        public ProductType $type,
        public string $name,
        public string $code,
        public array $fields,
        public int $defaultSlots = 1,
        public int $warrantyDays = 0,
        public int $minRemainingDays = 0,
        public ?int $lowStockThreshold = null,
        public ?Normalization $normalization = null,
    ) {}

    /**
     * Tuỳ chọn chuẩn hoá đã chọn, hoặc mặc định theo loại Sản phẩm.
     */
    public function normalization(): Normalization
    {
        return $this->normalization ?? $this->type->defaultNormalization();
    }
}
