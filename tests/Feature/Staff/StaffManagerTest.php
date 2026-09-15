<?php

use App\Filament\Pages\Auth\Login;
use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Security\SecurityEvent;
use App\Inventory\Staff\LastActiveQuanTri;
use App\Inventory\Staff\StaffManager;
use App\Models\SecurityLogEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = app(StaffManager::class);
});

it('Quản trị tạo nhân viên kèm Vai trò và ghi Nhật ký bảo mật', function () {
    $admin = staffMember(Role::QuanTri);

    $created = $this->staff->create($admin, 'Bình', 'binh@shop.test', 'mat-khau-ban-dau', [Role::NhapKho, Role::BanHang]);

    expect($created->fresh())
        ->name->toBe('Bình')
        ->email->toBe('binh@shop.test')
        ->and(Hash::check('mat-khau-ban-dau', $created->fresh()->password))->toBeTrue()
        ->and($created->hasAllRoles([Role::NhapKho, Role::BanHang]))->toBeTrue()
        ->and($created->hasRole(Role::QuanTri))->toBeFalse();

    $entry = SecurityLogEntry::where('event', SecurityEvent::StaffCreated)->sole();

    expect($entry)
        ->user_id->toBe($created->id)
        ->actor_id->toBe($admin->id)
        ->details->toBe(['roles' => ['nhap-kho', 'ban-hang']])
        ->and(json_encode($entry->toArray()))->not->toContain('mat-khau-ban-dau');
});

it('chỉ Quản trị tạo được nhân viên', function (Role $role) {
    expect(fn () => $this->staff->create(staffMember($role), 'Bình', 'binh@shop.test', 'mat-khau-ban-dau', [Role::BanHang]))
        ->toThrow(MissingRole::class);

    expect(User::where('email', 'binh@shop.test')->exists())->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffCreated)->exists())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Quản trị đổi Vai trò của nhân viên và ghi Nhật ký bảo mật', function () {
    $admin = staffMember(Role::QuanTri);
    $seller = staffMember(Role::BanHang);

    $this->staff->changeRoles($admin, $seller, [Role::NhapKho]);

    expect($seller->fresh()->hasRole(Role::NhapKho))->toBeTrue()
        ->and($seller->fresh()->hasRole(Role::BanHang))->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::RolesChanged)->sole())
        ->user_id->toBe($seller->id)
        ->actor_id->toBe($admin->id)
        ->details->toEqual(['from' => ['ban-hang'], 'to' => ['nhap-kho']]);
});

it('chỉ Quản trị đổi được Vai trò', function (Role $role) {
    $seller = staffMember(Role::BanHang);

    expect(fn () => $this->staff->changeRoles(staffMember($role), $seller, [Role::QuanTri]))
        ->toThrow(MissingRole::class);

    expect($seller->fresh()->hasRole(Role::QuanTri))->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::RolesChanged)->exists())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('chặn gỡ Vai trò Quản trị của Quản trị đang hoạt động cuối cùng', function () {
    $admin = staffMember(Role::QuanTri);

    expect(fn () => $this->staff->changeRoles($admin, $admin, [Role::BanHang]))
        ->toThrow(LastActiveQuanTri::class);

    expect($admin->fresh()->hasRole(Role::QuanTri))->toBeTrue()
        ->and(SecurityLogEntry::where('event', SecurityEvent::RolesChanged)->exists())->toBeFalse();
});

it('gỡ được Vai trò Quản trị khi còn Quản trị đang hoạt động khác', function () {
    $owner = staffMember(Role::QuanTri);
    $other = staffMember(Role::QuanTri);

    $this->staff->changeRoles($owner, $other, [Role::BanHang]);

    expect($other->fresh()->hasRole(Role::QuanTri))->toBeFalse();
});

it('Quản trị Khoá nhân viên và ghi Nhật ký bảo mật', function () {
    $admin = staffMember(Role::QuanTri);
    $seller = staffMember(Role::BanHang);

    $this->staff->deactivate($admin, $seller);

    expect($seller->fresh()->isDeactivated())->toBeTrue()
        ->and(app(RoleGate::class)->allows($seller->fresh(), Role::BanHang))->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffDeactivated)->sole())
        ->user_id->toBe($seller->id)
        ->actor_id->toBe($admin->id);
});

it('chỉ Quản trị Khoá được nhân viên', function (Role $role) {
    $seller = staffMember(Role::BanHang);

    expect(fn () => $this->staff->deactivate(staffMember($role), $seller))
        ->toThrow(MissingRole::class);

    expect($seller->fresh()->isDeactivated())->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffDeactivated)->exists())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Khoá nhân viên cắt ngay phiên đang mở', function () {
    $admin = staffMember(Role::QuanTri);
    $seller = staffMember(Role::BanHang);
    $panelUrl = Filament::getPanel('admin')->getUrl();

    // Đăng nhập qua session thật để request sau nạp lại nhân viên từ DB như trên production.
    Filament::auth()->login($seller);
    $this->get($panelUrl)->assertOk();

    $this->staff->deactivate($admin, $seller);
    $this->app['auth']->forgetGuards();

    $this->get($panelUrl)->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->assertGuest();
});

it('nhân viên bị khoá không đăng nhập được', function () {
    $admin = staffMember(Role::QuanTri);
    $seller = tap(User::factory()->withTwoFactor()->create(['email' => 'binh@shop.test']))->assignRole(Role::BanHang);
    $this->staff->deactivate($admin, $seller);

    Livewire::test(Login::class)
        ->set('data.email', 'binh@shop.test')
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasErrors('data.email');

    expect(Filament::auth()->check())->toBeFalse();
});

it('chặn Khoá Quản trị đang hoạt động cuối cùng', function () {
    $admin = staffMember(Role::QuanTri);

    expect(fn () => $this->staff->deactivate($admin, $admin))
        ->toThrow(LastActiveQuanTri::class);

    expect($admin->fresh()->isDeactivated())->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffDeactivated)->exists())->toBeFalse();
});

it('Quản trị đã bị khoá không tính là Quản trị đang hoạt động', function () {
    $owner = staffMember(Role::QuanTri);
    $other = staffMember(Role::QuanTri);
    $this->staff->deactivate($owner, $other);

    expect(fn () => $this->staff->changeRoles($owner, $owner, [Role::BanHang]))
        ->toThrow(LastActiveQuanTri::class);
});

it('Quản trị reset 2FA của nhân viên, buộc thiết lập lại ở lần đăng nhập sau', function () {
    $admin = staffMember(Role::QuanTri);
    $seller = staffMember(Role::BanHang);
    $oldSecret = $seller->app_authentication_secret;

    $this->staff->resetTwoFactor($admin, $seller);

    $entry = SecurityLogEntry::where('event', SecurityEvent::TwoFactorReset)->sole();

    expect(AppAuthentication::make()->isEnabled($seller->fresh()))->toBeFalse()
        ->and($seller->fresh()->app_authentication_recovery_codes)->toBeNull()
        ->and($entry)
        ->user_id->toBe($seller->id)
        ->actor_id->toBe($admin->id)
        ->and(json_encode($entry->toArray()))->not->toContain($oldSecret);

    $this->actingAs($seller->fresh())
        ->get(Filament::getPanel('admin')->getUrl())
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('chỉ Quản trị reset được 2FA', function (Role $role) {
    $seller = staffMember(Role::BanHang);

    expect(fn () => $this->staff->resetTwoFactor(staffMember($role), $seller))
        ->toThrow(MissingRole::class);

    expect(AppAuthentication::make()->isEnabled($seller->fresh()))->toBeTrue()
        ->and(SecurityLogEntry::where('event', SecurityEvent::TwoFactorReset)->exists())->toBeFalse();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);

it('Quản trị mở khoá nhân viên và ghi Nhật ký bảo mật', function () {
    $admin = staffMember(Role::QuanTri);
    $seller = staffMember(Role::BanHang);
    $this->staff->deactivate($admin, $seller);

    $this->staff->reactivate($admin, $seller);

    expect($seller->fresh()->isDeactivated())->toBeFalse()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffReactivated)->sole())
        ->user_id->toBe($seller->id)
        ->actor_id->toBe($admin->id);
});

it('chỉ Quản trị mở khoá được nhân viên', function (Role $role) {
    $admin = staffMember(Role::QuanTri);
    $seller = staffMember(Role::BanHang);
    $this->staff->deactivate($admin, $seller);

    expect(fn () => $this->staff->reactivate(staffMember($role), $seller))
        ->toThrow(MissingRole::class);

    expect($seller->fresh()->isDeactivated())->toBeTrue();
})->with([
    'Nhập kho' => Role::NhapKho,
    'Bán hàng' => Role::BanHang,
]);
