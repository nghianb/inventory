<?php

namespace App\Filament\Pages\Auth;

use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Filament không phát sự kiện khi sai 2FA hay khi bị rate-limit, nên trang này
 * ghi các sự kiện đó vào Nhật ký bảo mật. Đăng nhập thành công/thất bại đi qua
 * sự kiện Auth của Laravel.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        try {
            return parent::authenticate();
        } catch (ValidationException $exception) {
            $this->recordFailedMultiFactorChallenge($exception);

            throw $exception;
        }
    }

    protected function recordFailedMultiFactorChallenge(ValidationException $exception): void
    {
        $failedMultiFactor = collect($exception->errors())
            ->keys()
            ->contains(fn (string $key): bool => Str::startsWith($key, 'data.multiFactor.'));

        $user = $this->getUserUndertakingMultiFactorAuthentication();

        if ($failedMultiFactor && $this->hasSubmittedMultiFactorCode() && $user instanceof User) {
            app(SecurityLog::class)->record(SecurityEvent::TwoFactorFailed, $user);
        }
    }

    protected function hasSubmittedMultiFactorCode(): bool
    {
        return collect(Arr::dot($this->data['multiFactor'] ?? []))
            ->contains(fn (mixed $value, string $key): bool => Str::endsWith($key, ['code', 'recoveryCode']) && filled($value));
    }

    /**
     * Filament gọi hàm này ở cả hai chỗ chặn vì rate-limit (bước mật khẩu và bước 2FA).
     */
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        $user = $this->getUserUndertakingMultiFactorAuthentication();
        $email = $this->data['email'] ?? null;

        app(SecurityLog::class)->record(
            SecurityEvent::LoginThrottled,
            $user instanceof User ? $user : null,
            is_string($email) ? $email : null,
        );

        return parent::getRateLimitedNotification($exception);
    }
}
