<?php

namespace App\Inventory\Catalog;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: xoá Sản phẩm đã có hàng. Dùng Ngừng bán thay thế.
 */
class ProductHasStock extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sản phẩm đã có hàng không xoá được, chỉ Ngừng bán.');
    }
}
