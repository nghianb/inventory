<?php

namespace App\Inventory\Dispatch;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: mã đơn ngoài đã thuộc một Phiếu xuất khác với đơn đang gửi (Dòng xuất khác, hoặc
 * phiếu không còn giao được). Mã đơn ngoài bị chiếm vĩnh viễn nên website phải dùng mã khác.
 */
class DispatchConflict extends RuntimeException
{
    public function __construct(public readonly int $dispatchId, string $message)
    {
        parent::__construct($message);
    }
}
