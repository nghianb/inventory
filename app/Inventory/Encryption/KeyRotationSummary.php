<?php

namespace App\Inventory\Encryption;

use Carbon\CarbonImmutable;

/**
 * Kết quả một lần xoay khoá, cho lệnh in ra và cho Nhật ký bảo mật. Không bao giờ mang giá trị
 * khoá: dấu vân tay là HMAC của một chuỗi cố định, đúng thứ DB đã lưu.
 */
final readonly class KeyRotationSummary
{
    public function __construct(
        public KeyPurpose $purpose,
        public ?int $fromVersion,
        public int $toVersion,
        public string $fingerprint,
        public int $rewritten,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
    ) {}

    /**
     * Dữ liệu ghi kèm vào Nhật ký bảo mật.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'fingerprint' => $this->fingerprint,
            'rewritten' => $this->rewritten,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
        ];
    }
}
