<?php

namespace App\Inventory\Dispatch;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Phiếu xuất không qua kiểm tra; không Slot nào được giao.
 */
class InvalidDispatch extends RuntimeException
{
    /**
     * @param  list<DispatchProblem>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', array_map(fn (DispatchProblem $problem): string => $problem->message, $problems)));
    }
}
