<?php

namespace App\Inventory\Dispatch;

/**
 * Kết quả màn kết quả xuất kho: nội dung từng Slot vừa giao, theo thứ tự giao. Phiếu từ
 * ngưỡng `inventory.dispatch.result_mask_slots` Slot trở lên chỉ có dạng che (`masked`): trường
 * nhạy cảm bị che, không có tin nhắn, không Copy từng Slot.
 */
final readonly class DispatchResultContent
{
    /**
     * @param  list<DeliveredContent>  $slots
     */
    public function __construct(
        public bool $masked,
        public array $slots,
    ) {}
}
