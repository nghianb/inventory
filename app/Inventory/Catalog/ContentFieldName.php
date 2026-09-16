<?php

namespace App\Inventory\Catalog;

/**
 * Tên tra một Trường nội dung. Định danh và tên hiển thị dùng chung một không gian tên,
 * vì cột file nhập khớp theo cả hai; so sau khi trim, không phân biệt hoa thường.
 */
final class ContentFieldName
{
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
