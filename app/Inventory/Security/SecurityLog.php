<?php

namespace App\Inventory\Security;

use App\Models\SecurityLogEntry;
use App\Models\User;

/**
 * Ghi Nhật ký bảo mật: ai, khi nào, sự kiện gì. Không bao giờ nhận giá trị khoá,
 * mật khẩu hay mã 2FA.
 */
class SecurityLog
{
    /**
     * @param  ?User  $user  nhân viên mà sự kiện nói tới
     * @param  ?User  $actor  Quản trị thực hiện thao tác lên $user, nếu có
     * @param  array<string, mixed>  $details  dữ liệu phụ không nhạy cảm, ví dụ Vai trò mới
     */
    public function record(
        SecurityEvent $event,
        ?User $user = null,
        ?string $email = null,
        ?User $actor = null,
        array $details = [],
    ): SecurityLogEntry {
        $request = request();

        return SecurityLogEntry::create([
            'event' => $event,
            'user_id' => $user?->getKey(),
            'actor_id' => $actor?->getKey(),
            'email' => $email ?? $user?->email,
            'details' => $details === [] ? null : $details,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
