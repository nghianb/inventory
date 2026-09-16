<?php

namespace App\Inventory\Dispatch;

use App\Models\Dispatch;

/**
 * Kết quả một đơn qua API: Phiếu xuất và nội dung từng Slot đã giao. `replayed` cho biết đây là
 * lần gửi lại một mã đơn đã có (phiếu cũ, không giao thêm gì) hay đơn vừa được giao.
 */
final readonly class ApiDispatchResult
{
    /**
     * @param  list<DeliveredContent>  $slots  theo thứ tự giao
     */
    public function __construct(
        public Dispatch $dispatch,
        public bool $replayed,
        public array $slots,
    ) {}
}
