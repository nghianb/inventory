<?php

namespace App\Inventory\Security;

/**
 * Loại sự kiện ghi vào Nhật ký bảo mật.
 */
enum SecurityEvent: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case TwoFactorFailed = 'two_factor_failed';
    case LoginThrottled = 'login_throttled';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Đăng nhập thành công',
            self::LoginFailed => 'Đăng nhập thất bại',
            self::TwoFactorFailed => 'Nhập sai 2FA',
            self::LoginThrottled => 'Bị chặn vì thử quá nhiều lần',
        };
    }
}
