<?php

namespace App\Inventory\Encryption;

use RuntimeException;

/**
 * Khoá mã hoá trong môi trường thiếu hoặc sai định dạng. Thông báo không chứa giá trị khoá.
 */
class InvalidKeyConfiguration extends RuntimeException
{
    public static function missing(KeyPurpose $purpose): self
    {
        return new self("Chưa cấu hình {$purpose->label()} của kho.");
    }

    public static function malformed(KeyPurpose $purpose): self
    {
        return new self("Cấu hình {$purpose->label()} sai định dạng: cần \"<phiên bản>:base64:<32 byte>\", phiên bản không trùng nhau.");
    }

    public static function missingVersion(KeyPurpose $purpose, int $version): self
    {
        return new self("Môi trường không có {$purpose->label()} phiên bản {$version}, kể cả trong danh sách khoá cũ.");
    }
}
