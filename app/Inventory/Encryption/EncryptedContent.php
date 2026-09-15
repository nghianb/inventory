<?php

namespace App\Inventory\Encryption;

/**
 * Nội dung một Trường nội dung đã mã hoá, kèm phiên bản khoá nội dung đã dùng. Bản ghi
 * lưu cả hai để lệnh xoay khoá tìm được bản ghi còn mã hoá bằng khoá cũ.
 */
final readonly class EncryptedContent
{
    public function __construct(
        public string $ciphertext,
        public int $keyVersion,
    ) {}
}
