<?php

namespace App\Inventory\Catalog;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: xoá Sản phẩm đã có hàng hoặc đã có Lô nhập. Dùng Ngừng bán thay thế.
 */
class ProductHasStock extends RuntimeException
{
    public function __construct(string $message = 'Sản phẩm đã có hàng không xoá được, chỉ Ngừng bán.')
    {
        parent::__construct($message);
    }
}
