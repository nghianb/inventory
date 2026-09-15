<?php

namespace App\Inventory\Reveal;

use App\Models\User;

/**
 * "Ai" trong Nhật ký xem mã: một nhân viên, hoặc một Khoá API khi nội dung được trả qua API.
 */
final readonly class RevealActor
{
    private function __construct(
        public ?User $user,
        public ?int $apiKeyId,
    ) {}

    public static function staff(User $user): self
    {
        return new self($user, null);
    }

    public static function apiKey(int $apiKeyId): self
    {
        return new self(null, $apiKeyId);
    }
}
