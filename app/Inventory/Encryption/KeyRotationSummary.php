<?php

namespace App\Inventory\Encryption;

use Carbon\CarbonImmutable;

/**
 * Kết quả một lần xoay khoá, để lệnh in ra cho Người vận hành server. Dòng Nhật ký bảo mật do
 * {@see KeyRotation} tự dựng, vì nó còn mang dấu vân tay mà lệnh không in.
 */
final readonly class KeyRotationSummary
{
    public function __construct(
        public KeyPurpose $purpose,
        public ?int $fromVersion,
        public int $toVersion,
        public int $rewritten,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
    ) {}
}
