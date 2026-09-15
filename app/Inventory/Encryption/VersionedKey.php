<?php

namespace App\Inventory\Encryption;

use SensitiveParameter;

/**
 * Một khoá mã hoá kèm phiên bản. Không bao giờ lộ giá trị khoá khi dump hay ghi log.
 *
 * @internal chỉ module Mã hoá dùng; phần còn lại của hệ thống không chạm tới khoá.
 */
final readonly class VersionedKey
{
    public function __construct(
        public KeyPurpose $purpose,
        public int $version,
        #[SensitiveParameter] public string $material,
    ) {}

    public function describe(): string
    {
        return "{$this->purpose->label()} phiên bản {$this->version}";
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['purpose' => $this->purpose, 'version' => $this->version];
    }
}
