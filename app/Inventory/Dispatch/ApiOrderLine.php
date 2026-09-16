<?php

namespace App\Inventory\Dispatch;

/**
 * Một dòng của đơn qua API: website tham chiếu Sản phẩm bằng Mã sản phẩm, và Giá bán là tổng tiền
 * của cả dòng chứ không phải đơn giá.
 */
final readonly class ApiOrderLine
{
    public function __construct(
        public string $productCode,
        public int $quantity,
        public ?int $salePrice = null,
    ) {}
}
