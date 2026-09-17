<?php

namespace App\Inventory\Dispatch;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Trạng thái Tạm dừng xuất kho lúc này, để panel hiển thị. Không dùng để quyết định một lần giao có
 * được chạy hay không: quyết định ấy phải đọc cờ trong cùng transaction với lần giao, qua
 * {@see DispatchFreeze::guard()}.
 */
final readonly class FreezeState
{
    public function __construct(
        public ?CarbonImmutable $frozenAt = null,
        public ?string $reason = null,
        public ?User $actor = null,
    ) {}

    public function isFrozen(): bool
    {
        return $this->frozenAt !== null;
    }

    /**
     * Ai bật: tên Quản trị, hoặc lệnh trên server khi kho tự dừng sau khôi phục.
     */
    public function actorLabel(): string
    {
        return $this->actor === null ? 'Lệnh trên server' : $this->actor->name;
    }
}
