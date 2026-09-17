<?php

use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Filament\Resources\Staff\StaffResource;
use App\Inventory\Access\Role;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('chỉ Quản trị vào được trang Nhân viên', function (Role $role, int $status) {
    $this->actingAs(staffMember($role))
        ->get(StaffResource::getUrl('index'))
        ->assertStatus($status);
})->with([
    'Quản trị' => [Role::Owner, 200],
    'Nhập kho' => [Role::NhapKho, 403],
    'Bán hàng' => [Role::BanHang, 403],
]);

it('Quản trị tạo nhân viên từ panel', function () {
    $this->actingAs($admin = staffMember(Role::Owner));

    Livewire::test(ManageStaff::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Bình',
            'email' => 'binh@shop.test',
            'password' => 'mat-khau-ban-dau',
            'roles' => [Role::BanHang->value],
        ])
        ->assertHasNoFormErrors();

    $created = User::where('email', 'binh@shop.test')->sole();

    expect($created->hasRole(Role::BanHang))->toBeTrue()
        ->and(SecurityLogEntry::where('event', SecurityEvent::StaffCreated)->sole()->actor_id)->toBe($admin->id);
});

it('Quản trị đổi Vai trò, Khoá, mở khoá và reset 2FA từ panel', function () {
    $this->actingAs(staffMember(Role::Owner));
    $seller = staffMember(Role::BanHang);

    Livewire::test(ManageStaff::class)
        ->callAction(TestAction::make('changeRoles')->table($seller), data: ['roles' => [Role::NhapKho->value]])
        ->callAction(TestAction::make('resetTwoFactor')->table($seller))
        ->callAction(TestAction::make('deactivate')->table($seller))
        ->assertActionHidden(TestAction::make('deactivate')->table($seller))
        ->callAction(TestAction::make('reactivate')->table($seller));

    $seller->refresh();

    expect($seller->hasRole(Role::NhapKho))->toBeTrue()
        ->and($seller->hasRole(Role::BanHang))->toBeFalse()
        ->and(AppAuthentication::make()->isEnabled($seller))->toBeFalse()
        ->and($seller->isDeactivated())->toBeFalse()
        ->and(SecurityLogEntry::pluck('event')->all())->toEqualCanonicalizing([
            SecurityEvent::RolesChanged,
            SecurityEvent::TwoFactorReset,
            SecurityEvent::StaffDeactivated,
            SecurityEvent::StaffReactivated,
        ]);
});

it('panel báo lỗi thay vì Khoá Quản trị đang hoạt động cuối cùng', function () {
    $this->actingAs($admin = staffMember(Role::Owner));

    Livewire::test(ManageStaff::class)
        ->callAction(TestAction::make('deactivate')->table($admin))
        ->assertNotified('Kho phải luôn còn ít nhất một Quản trị đang hoạt động.');

    expect($admin->fresh()->isDeactivated())->toBeFalse();
});

it('không có thao tác xoá nhân viên, kể cả với Quản trị', function () {
    $this->actingAs($admin = staffMember(Role::Owner));
    $seller = staffMember(Role::BanHang);

    Livewire::test(ManageStaff::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($seller))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

    expect($admin->can('delete', $seller))->toBeFalse()
        ->and($admin->can('deleteAny', User::class))->toBeFalse()
        ->and($admin->can('forceDelete', $seller))->toBeFalse();
});
