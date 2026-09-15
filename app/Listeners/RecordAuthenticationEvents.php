<?php

namespace App\Listeners;

use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Events\Dispatcher;

/**
 * Chuyển sự kiện xác thực của Laravel thành dòng Nhật ký bảo mật.
 */
class RecordAuthenticationEvents
{
    public function __construct(private SecurityLog $log) {}

    public function onLogin(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->log->record(SecurityEvent::LoginSucceeded, $user);
    }

    public function onFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $email = $event->credentials['email'] ?? null;

        $this->log->record(SecurityEvent::LoginFailed, $user, is_string($email) ? $email : null);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
        ];
    }
}
