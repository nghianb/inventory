<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Reveal\RevealActor;
use App\Models\ApiKey;
use App\Models\User;

/**
 * "Ai" của một lần xuất kho: nhân viên trong panel, hoặc Khoá API của một Kênh bán loại API.
 * Phiếu xuất, Sổ biến động kho và Nhật ký xem mã của lần xuất ấy đều ghi cùng tác nhân này.
 */
final readonly class DispatchActor
{
    private function __construct(
        public ?User $user,
        public ?ApiKey $apiKey,
    ) {}

    public static function staff(User $user): self
    {
        return new self($user, null);
    }

    public static function apiKey(ApiKey $key): self
    {
        return new self(null, $key);
    }

    public function revealActor(): RevealActor
    {
        return $this->user === null
            ? RevealActor::apiKey((int) $this->apiKey?->getKey())
            : RevealActor::staff($this->user);
    }
}
