<?php

namespace App\Inventory\Warranty;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Báo lỗi không tạo hoặc không xác minh được; không có gì thay đổi.
 */
class InvalidDefectReport extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
