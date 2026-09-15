<?php

namespace App\Inventory\Dispatch;

/**
 * Một lỗi kiểm tra của Phiếu xuất. Mã đơn trùng kèm phiếu đã chiếm mã để nhân viên mở ra xem.
 */
final readonly class DispatchProblem
{
    public function __construct(
        public string $message,
        public ?int $existingDispatchId = null,
    ) {}
}
