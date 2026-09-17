<?php

namespace App\Inventory\Catalog;

/**
 * Khai báo một Trường nội dung khi tạo hoặc sửa Loại sản phẩm. `key` là định danh ổn định
 * của trường (dùng trong file nhập, Mẫu giao hàng, API); `label` là tên hiển thị.
 */
final readonly class ContentFieldDraft
{
    public function __construct(
        public string $key,
        public string $label,
        public ContentFieldType $type = ContentFieldType::Text,
        public ?string $pattern = null,
        public bool $required = true,
        public bool $sensitive = true,
        public bool $dedupeKey = false,
    ) {}
}
