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
    case StaffCreated = 'staff_created';
    case RolesChanged = 'roles_changed';
    case StaffDeactivated = 'staff_deactivated';
    case StaffReactivated = 'staff_reactivated';
    case TwoFactorReset = 'two_factor_reset';
    case KeyFingerprintRegistered = 'key_fingerprint_registered';
    case ApiKeyCreated = 'api_key_created';
    case ApiKeyRotated = 'api_key_rotated';
    case ApiKeyRevoked = 'api_key_revoked';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Đăng nhập thành công',
            self::LoginFailed => 'Đăng nhập thất bại',
            self::TwoFactorFailed => 'Nhập sai 2FA',
            self::LoginThrottled => 'Bị chặn vì thử quá nhiều lần',
            self::StaffCreated => 'Tạo nhân viên',
            self::RolesChanged => 'Đổi Vai trò',
            self::StaffDeactivated => 'Khoá nhân viên',
            self::StaffReactivated => 'Mở khoá nhân viên',
            self::TwoFactorReset => 'Reset 2FA',
            self::KeyFingerprintRegistered => 'Đăng ký dấu vân tay khoá mã hoá',
            self::ApiKeyCreated => 'Tạo Khoá API',
            self::ApiKeyRotated => 'Xoay Khoá API',
            self::ApiKeyRevoked => 'Thu hồi Khoá API',
        };
    }
}
