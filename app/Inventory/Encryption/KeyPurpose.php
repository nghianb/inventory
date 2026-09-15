<?php

namespace App\Inventory\Encryption;

/**
 * Các khoá mã hoá của kho mà ứng dụng cầm. APP_KEY không thuộc module này.
 */
enum KeyPurpose: string
{
    case Content = 'content';
    case Hmac = 'hmac';
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::Content => 'khoá mã hoá nội dung',
            self::Hmac => 'khoá mã hoá HMAC',
            self::Backup => 'khoá mã hoá backup',
        };
    }

    /**
     * Chỉ khoá nội dung giữ khoá cũ để giải mã; xoay khoá HMAC tính lại mọi hash, không
     * giữ hai khoá song song.
     */
    public function keepsPreviousKeys(): bool
    {
        return $this === self::Content;
    }
}
