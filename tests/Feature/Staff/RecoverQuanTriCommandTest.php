<?php

use App\Inventory\Access\Role;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function lockedOutQuanTri(string $email = 'chu@shop.test'): User
{
    return tap(User::factory()->withTwoFactor()->create([
        'email' => $email,
        'deactivated_at' => now(),
    ]))->assignRole(Role::QuanTri);
}

it('mở khoá Quản trị từ server và ghi Nhật ký bảo mật', function () {
    $admin = lockedOutQuanTri();

    $this->artisan('staff:recover-quan-tri', ['email' => 'chu@shop.test', '--unlock' => true])
        ->assertSuccessful();

    expect($admin->fresh()->isDeactivated())->toBeFalse()
        ->and(AppAuthentication::make()->isEnabled($admin->fresh()))->toBeTrue()
        ->and(SecurityLogEntry::sole())
        ->event->toBe(SecurityEvent::StaffReactivated)
        ->user_id->toBe($admin->id)
        ->actor_id->toBeNull()
        ->details->toBe(['via' => 'artisan']);
});

it('reset 2FA của Quản trị từ server và ghi Nhật ký bảo mật', function () {
    $admin = lockedOutQuanTri();

    $this->artisan('staff:recover-quan-tri', ['email' => 'chu@shop.test', '--reset-2fa' => true])
        ->assertSuccessful();

    expect(AppAuthentication::make()->isEnabled($admin->fresh()))->toBeFalse()
        ->and($admin->fresh()->isDeactivated())->toBeTrue()
        ->and(SecurityLogEntry::sole())
        ->event->toBe(SecurityEvent::TwoFactorReset)
        ->user_id->toBe($admin->id)
        ->actor_id->toBeNull()
        ->details->toBe(['via' => 'artisan']);
});

it('mở khoá và reset 2FA cùng lúc', function () {
    $admin = lockedOutQuanTri();

    $this->artisan('staff:recover-quan-tri', ['email' => 'chu@shop.test', '--unlock' => true, '--reset-2fa' => true])
        ->assertSuccessful();

    expect($admin->fresh()->isDeactivated())->toBeFalse()
        ->and(AppAuthentication::make()->isEnabled($admin->fresh()))->toBeFalse()
        ->and(SecurityLogEntry::pluck('event')->all())
        ->toEqualCanonicalizing([SecurityEvent::StaffReactivated, SecurityEvent::TwoFactorReset]);
});

it('từ chối khôi phục cho nhân viên không mang Vai trò Quản trị', function () {
    $seller = tap(User::factory()->withTwoFactor()->create([
        'email' => 'binh@shop.test',
        'deactivated_at' => now(),
    ]))->assignRole(Role::BanHang);

    $this->artisan('staff:recover-quan-tri', ['email' => 'binh@shop.test', '--unlock' => true])
        ->assertFailed();

    expect($seller->fresh()->isDeactivated())->toBeTrue()
        ->and(SecurityLogEntry::count())->toBe(0);
});

it('báo lỗi khi email không tồn tại hoặc không chọn thao tác nào', function (array $arguments) {
    lockedOutQuanTri();

    $this->artisan('staff:recover-quan-tri', $arguments)->assertFailed();

    expect(SecurityLogEntry::count())->toBe(0);
})->with([
    'email không tồn tại' => [['email' => 'la@shop.test', '--unlock' => true]],
    'không chọn thao tác' => [['email' => 'chu@shop.test']],
]);
