<?php

namespace App\Inventory\Catalog;

use App\Inventory\Encryption\Normalization;

/**
 * Toàn bộ khai báo của một Loại sản phẩm khi tạo hoặc sửa.
 */
final readonly class ProductTypeDraft
{
    /**
     * @param  list<ContentFieldDraft>  $fields  theo thứ tự hiển thị
     */
    public function __construct(
        public string $name,
        public StockForm $form,
        public array $fields,
        public ?Normalization $normalization = null,
        public ?string $deliveryTemplate = null,
    ) {}

    /**
     * Tuỳ chọn chuẩn hoá đã chọn, hoặc mặc định theo Dạng hàng.
     */
    public function normalization(): Normalization
    {
        return $this->normalization ?? $this->form->defaultNormalization();
    }
}
