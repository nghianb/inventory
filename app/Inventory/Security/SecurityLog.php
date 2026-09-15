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
    public function record(SecurityEvent $event, ?User $user = null, ?string $email = null): SecurityLogEntry
    {
        $request = request();

        return SecurityLogEntry::create([
            'event' => $event,
            'user_id' => $user?->getKey(),
            'email' => $email ?? $user?->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
