<?php

namespace App\Inventory\Dispatch;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: không đủ hàng cho ít nhất một Dòng xuất nên cả Phiếu xuất không giao gì.
 */
class OutOfStock extends RuntimeException
{
    /**
     * @param  list<Shortage>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Không đủ hàng, không giao gì: '.implode('; ', array_map(
            fn (Shortage $shortage): string => sprintf(
                '"%s" cần %s, còn %s',
                $shortage->productName,
                number_format($shortage->needed, 0, ',', '.'),
                number_format($shortage->available, 0, ',', '.'),
            ),
            $shortages,
        )).'.');
    }
}
