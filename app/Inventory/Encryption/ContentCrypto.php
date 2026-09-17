<?php

namespace App\Inventory\Encryption;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use JsonException;
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

    /**
     * Các Trường nội dung nhạy cảm đã giải mã của một Đơn vị hàng, theo định danh trường; rỗng khi
     * hàng không có trường nhạy cảm nào.
     *
     * @return array<string, string>
     *
     * @throws DecryptException
     * @throws InvalidKeyConfiguration
     * @throws JsonException
     */
    public function decryptFields(?string $ciphertext, ?int $keyVersion): array
    {
        if ($ciphertext === null) {
            return [];
        }

        /** @var array<string, string> $values */
        $values = json_decode($this->decrypt(new EncryptedContent($ciphertext, (int) $keyVersion)), true, flags: JSON_THROW_ON_ERROR);

        return $values;
    }

    /**
     * Phiên bản khoá mã hoá HMAC đang tính hash Khoá chống trùng. Bản ghi lưu lại phiên bản này để
     * lệnh xoay khoá HMAC biết hàng nào còn ở khoá cũ.
     *
     * @throws InvalidKeyConfiguration
     */
    public function hmacKeyVersion(): int
    {
        return $this->keys->current(KeyPurpose::Hmac)->version;
    }

    private static function encrypter(VersionedKey $key): Encrypter
    {
        return new Encrypter($key->material, self::CIPHER);
    }
}
