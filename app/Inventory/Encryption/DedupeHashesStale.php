<?php

namespace App\Inventory\Encryption;

use RuntimeException;

/**
 * Tra cứu theo Khoá chống trùng trong lúc kho còn lẫn hash cũ và hash mới (lệnh xoay khoá mã hoá
 * HMAC đang chạy dở hoặc đã bị ngắt). Báo lỗi thay vì trả rỗng: "không tìm thấy" ở đây là sai sự
 * thật, mà Ghi nhận giao bù lại tra đúng lúc kho đang được khôi phục.
 */
class DedupeHashesStale extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Đang xoay khoá mã hoá HMAC nên tra cứu theo Khoá chống trùng chưa chính xác; thử lại khi lệnh xoay khoá chạy xong.');
    }
}
