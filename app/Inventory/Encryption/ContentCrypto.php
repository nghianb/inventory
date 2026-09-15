<?php

namespace App\Inventory\Encryption;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;

/**
 * Mọi việc với nội dung Đơn vị hàng cần tới khoá mã hoá: mã hoá và giải mã Trường nội
 * dung (Encrypter của Laravel, aes-256-gcm, khoá nội dung riêng tách khỏi APP_KEY, có
 * khoá cũ riêng) và tính hash của Khoá chống trùng (khoá HMAC). Phần còn lại của hệ
 * thống không chạm tới khoá.
 */
final class ContentCrypto
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private KeyRing $keys) {}

    /**
     * @throws InvalidKeyConfiguration
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): EncryptedContent
    {
        $key = $this->keys->current(KeyPurpose::Content);

        return new EncryptedContent(self::encrypter($key)->encryptString($plaintext), $key->version);
    }

    /**
     * Giải mã bằng đúng phiên bản khoá nội dung đã mã hoá bản ghi: khoá hiện hành hoặc khoá cũ.
     *
     * @throws DecryptException
     * @throws InvalidKeyConfiguration môi trường không còn khoá phiên bản đó
     */
    public function decrypt(EncryptedContent $content): string
    {
        return self::encrypter($this->keys->find(KeyPurpose::Content, $content->keyVersion))
            ->decryptString($content->ciphertext);
    }

    /**
     * Hash của Khoá chống trùng: HMAC-SHA256 đầy đủ (64 ký tự hex, không cắt ngắn) của chuỗi
     * đã chuẩn hoá, bằng khoá HMAC. Dùng HMAC thay vì hash thường vì không gian mã nhỏ, dò
     * ngược được từ bản dump DB.
     *
     * @throws InvalidKeyConfiguration
     */
    public function dedupeHash(#[SensitiveParameter] string $value, Normalization $normalization): string
    {
        return hash_hmac('sha256', $normalization->apply($value), $this->keys->current(KeyPurpose::Hmac)->material);
    }

    private static function encrypter(VersionedKey $key): Encrypter
    {
        return new Encrypter($key->material, self::CIPHER);
    }
}
