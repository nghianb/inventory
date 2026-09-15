<?php

namespace App\Inventory\Encryption;

use Illuminate\Contracts\Config\Repository;
use SensitiveParameter;

/**
 * Đọc các khoá mã hoá có phiên bản từ cấu hình `inventory.keys` (lấy từ môi trường).
 *
 * @internal chỉ module Mã hoá dùng; phần còn lại của hệ thống không chạm tới khoá.
 */
final class KeyRing
{
    private const KEY_BYTES = 32;

    public function __construct(private Repository $config) {}

    /**
     * @throws InvalidKeyConfiguration
     */
    public function current(KeyPurpose $purpose): VersionedKey
    {
        $value = $this->config->get("inventory.keys.{$purpose->value}");

        if (! is_string($value) || $value === '') {
            throw InvalidKeyConfiguration::missing($purpose);
        }

        return self::parse($purpose, $value);
    }

    /**
     * Khoá cũ còn dùng để giải mã, chỉ có ở loại khoá giữ khoá cũ.
     *
     * @return list<VersionedKey>
     *
     * @throws InvalidKeyConfiguration
     */
    public function previous(KeyPurpose $purpose): array
    {
        if (! $purpose->keepsPreviousKeys()) {
            return [];
        }

        $values = (array) $this->config->get("inventory.keys.{$purpose->value}_previous", []);
        $keys = array_values(array_map(fn (mixed $value) => self::parse($purpose, (string) $value), $values));

        $versions = array_map(fn (VersionedKey $key) => $key->version, [$this->current($purpose), ...$keys]);

        if (count($versions) !== count(array_unique($versions))) {
            throw InvalidKeyConfiguration::malformed($purpose);
        }

        return $keys;
    }

    /**
     * Khoá hiện hành hoặc khoá cũ có đúng phiên bản.
     *
     * @throws InvalidKeyConfiguration
     */
    public function find(KeyPurpose $purpose, int $version): VersionedKey
    {
        foreach ([$this->current($purpose), ...$this->previous($purpose)] as $key) {
            if ($key->version === $version) {
                return $key;
            }
        }

        throw InvalidKeyConfiguration::missingVersion($purpose, $version);
    }

    private static function parse(KeyPurpose $purpose, #[SensitiveParameter] string $value): VersionedKey
    {
        if (preg_match('/^([1-9]\d*):base64:(\S+)$/', trim($value), $matches) !== 1) {
            throw InvalidKeyConfiguration::malformed($purpose);
        }

        $material = base64_decode($matches[2], true);

        if ($material === false || strlen($material) !== self::KEY_BYTES) {
            throw InvalidKeyConfiguration::malformed($purpose);
        }

        return new VersionedKey($purpose, (int) $matches[1], $material);
    }
}
