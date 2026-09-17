<?php

namespace App\Inventory\Catalog;

/**
 * Lỗi nghiệp vụ: xoá Loại sản phẩm đang có Sản phẩm dùng. Dùng Ngừng dùng thay thế.
 */
class ProductTypeInUse extends InvalidProductConfiguration
{
    public function __construct(string $message = 'Loại sản phẩm đang có Sản phẩm dùng nên không xoá được, chỉ Ngừng dùng.')
    {
        parent::__construct($message);
    }
}
