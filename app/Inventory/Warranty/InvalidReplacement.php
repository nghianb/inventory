<?php

namespace App\Inventory\Warranty;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Đổi hàng không qua kiểm tra; không Slot nào được giao.
 */
class InvalidReplacement extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
