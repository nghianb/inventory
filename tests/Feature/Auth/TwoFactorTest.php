<?php

use App\Filament\Pages\Auth\Login;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function passwordStep(string $email): Testable
{
    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', 'password')
        ->call('authenticate');
}

it('không cho nhân viên chưa bật 2FA vào panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('cho nhân viên đã bật 2FA vào panel', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertOk();
});

it('đòi mã 2FA sau mật khẩu đúng, chưa tính là đăng nhập', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'an@shop.test']);

    passwordStep('an@shop.test')->assertHasNoErrors();

    expect(Filament::auth()->check())->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::LoginSucceeded)->exists())->toBeFalse();
});

it('đăng nhập được với mã TOTP đúng', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'an@shop.test']);

    passwordStep('an@shop.test')
        ->set('data.multiFactor.app.code', AppAuthentication::make()->getCurrentCode($user))
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Filament::auth()->id())->toBe($user->id)
        ->and(SecurityLogEntry::sole())
        ->event->toBe(SecurityEvent::LoginSucceeded)
        ->user_id->toBe($user->id);
});

it('ghi Nhật ký bảo mật khi nhập sai mã 2FA, không lưu mã đã gõ', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'an@shop.test']);
    $wrongCode = AppAuthentication::make()->getCurrentCode($user) === '000000' ? '111111' : '000000';

    passwordStep('an@shop.test')
        ->set('data.multiFactor.app.code', $wrongCode)
        ->call('authenticate')
        ->assertHasErrors('data.multiFactor.app.code');

    $entry = SecurityLogEntry::sole();

    expect(Filament::auth()->check())->toBeFalse()
        ->and($entry)
        ->event->toBe(SecurityEvent::TwoFactorFailed)
        ->user_id->toBe($user->id)
        ->and(json_encode($entry->only(['email', 'ip_address', 'user_agent'])))->not->toContain($wrongCode);
});

it('không ghi sai 2FA khi nhân viên bấm gửi mà chưa gõ mã', function () {
    User::factory()->withTwoFactor()->create(['email' => 'an@shop.test']);

    passwordStep('an@shop.test')
        ->call('authenticate')
        ->assertHasErrors('data.multiFactor.app.code');

    expect(SecurityLogEntry::count())->toBe(0);
});

it('ghi Nhật ký bảo mật cho nhân viên đang ở bước 2FA khi bị chặn vì thử quá nhiều lần', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'an@shop.test']);
    $wrongCode = AppAuthentication::make()->getCurrentCode($user) === '000000' ? '111111' : '000000';

    // Bước mật khẩu đã tính 1 lần; giới hạn 5 lần đếm chung cả bước 2FA.
    $login = passwordStep('an@shop.test')->set('data.multiFactor.app.code', $wrongCode);

    foreach (range(1, 5) as $attempt) {
        $login->call('authenticate');
    }

    expect(SecurityLogEntry::where('event', SecurityEvent::LoginThrottled)->sole())
        ->user_id->toBe($user->id);
});

it('đăng nhập được bằng recovery code và mỗi code chỉ dùng một lần', function () {
    $user = User::factory()->withTwoFactor(recoveryCodes: ['recovery-1', 'recovery-2'])->create(['email' => 'an@shop.test']);

    passwordStep('an@shop.test')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'recovery-1')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Filament::auth()->id())->toBe($user->id);

    Filament::auth()->logout();

    passwordStep('an@shop.test')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'recovery-1')
        ->call('authenticate')
        ->assertHasErrors('data.multiFactor.app.recoveryCode');

    expect(Filament::auth()->check())->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::TwoFactorFailed)->count())->toBe(1);
});
