<?php

namespace App\Inventory\Dispatch;

use App\Models\Dispatch;

/**
 * Kết quả một thao tác của website trên một Phiếu xuất: phiếu ở trạng thái hiện tại và các lần Giao
 * hàng của nó. `replayed` cho biết đây là lần gửi lại một mã đơn đã có (phiếu cũ, không giữ hay giao
 * thêm gì) hay đơn vừa được nhận.
 */
final readonly class ApiDispatchResult
{
    /**
     * @param  list<ApiDelivery>  $deliveries  theo thứ tự giao; rỗng khi phiếu chưa giao Slot nào
     */
    public function __construct(
        public Dispatch $dispatch,
        public bool $replayed,
        public array $deliveries,
    ) {}
}
