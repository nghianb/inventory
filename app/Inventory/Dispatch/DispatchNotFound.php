<?php

namespace App\Inventory\Dispatch;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: mã đơn ngoài website hỏi tới chưa thuộc Phiếu xuất nào trong Kênh bán của Khoá API.
 * Khác {@see DispatchConflict}: ở đây mã còn trống, website gửi đơn cho mã ấy được.
 */
class DispatchNotFound extends RuntimeException
{
    public function __construct(public readonly string $externalRef)
    {
        parent::__construct("Không có Phiếu xuất nào với mã đơn ngoài \"{$externalRef}\".");
    }
}
