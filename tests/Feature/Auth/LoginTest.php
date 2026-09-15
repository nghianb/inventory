<?php

use App\Filament\Pages\Auth\Login;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use App\Models\User;
use Livewire\Livewire;

it('ghi Nhật ký bảo mật khi đăng nhập thành công', function () {
    $user = User::factory()->create(['email' => 'an@shop.test']);

    Livewire::test(Login::class)
        ->set('data.email', 'an@shop.test')
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(SecurityLogEntry::sole())
        ->event->toBe(SecurityEvent::LoginSucceeded)
        ->user_id->toBe($user->id);
});

it('ghi Nhật ký bảo mật khi đăng nhập thất bại, không lưu mật khẩu đã gõ', function () {
    $user = User::factory()->create(['email' => 'an@shop.test']);

    Livewire::test(Login::class)
        ->set('data.email', 'an@shop.test')
        ->set('data.password', 'mat-khau-sai-123')
        ->call('authenticate')
        ->assertHasErrors('data.email');

    $entry = SecurityLogEntry::sole();

    expect($entry)
        ->event->toBe(SecurityEvent::LoginFailed)
        ->user_id->toBe($user->id)
        ->email->toBe('an@shop.test')
        ->and(json_encode($entry->only(['email', 'ip_address', 'user_agent'])))->not->toContain('mat-khau-sai-123');
});

it('ghi Nhật ký bảo mật khi đăng nhập bằng email không tồn tại', function () {
    Livewire::test(Login::class)
        ->set('data.email', 'la@shop.test')
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(SecurityLogEntry::sole())
        ->event->toBe(SecurityEvent::LoginFailed)
        ->user_id->toBeNull()
        ->email->toBe('la@shop.test');
});

it('ghi Nhật ký bảo mật khi đăng nhập bị chặn vì thử quá nhiều lần', function () {
    User::factory()->create(['email' => 'an@shop.test']);

    $login = Livewire::test(Login::class)
        ->set('data.email', 'an@shop.test')
        ->set('data.password', 'mat-khau-sai');

    foreach (range(1, 6) as $attempt) {
        $login->call('authenticate');
    }

    expect(SecurityLogEntry::where('event', SecurityEvent::LoginFailed)->count())->toBe(5)
        ->and(SecurityLogEntry::where('event', SecurityEvent::LoginThrottled)->sole())
        ->email->toBe('an@shop.test');
});
