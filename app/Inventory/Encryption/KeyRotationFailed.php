<?php

namespace App\Inventory\Encryption;

use RuntimeException;

/**
 * Lệnh xoay khoá gặp dữ liệu không xoay được. Thông báo chỉ nêu bản ghi nào, không bao giờ nêu
 * nội dung hàng hay giá trị khoá.
 */
class KeyRotationFailed extends RuntimeException
{
    public static function missingDedupeValue(int $stockUnitId): self
    {
        return new self("Đơn vị hàng #{$stockUnitId} không có giá trị Khoá chống trùng để tính lại hash; sửa dữ liệu rồi chạy lại lệnh.");
    }
}
