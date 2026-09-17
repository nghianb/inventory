<?php

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('seeder tạo đúng ba Vai trò, không có quyền lẻ, chạy lại không sinh trùng', function () {
    $this->seed(RoleSeeder::class);

    expect(RoleModel::pluck('name')->sort()->values()->all())->toBe(['ban-hang', 'nhap-kho', 'owner'])
        ->and(Permission::count())->toBe(0)
        ->and(Role::Owner->label())->toBe('Quản trị')
        ->and(Role::NhapKho->label())->toBe('Nhập kho')
        ->and(Role::BanHang->label())->toBe('Bán hàng');
});

it('một nhân viên mang được nhiều Vai trò', function () {
    $user = User::factory()->create();

    $user->assignRole(Role::NhapKho, Role::BanHang);

    $gate = app(RoleGate::class);

    expect($gate->allows($user, Role::NhapKho))->toBeTrue()
        ->and($gate->allows($user, Role::BanHang))->toBeTrue()
        ->and($gate->allows($user, Role::Owner))->toBeFalse();
});

it('Quản trị luôn qua mọi kiểm tra Vai trò', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Owner);

    $gate = app(RoleGate::class);

    expect($gate->allows($admin, Role::NhapKho))->toBeTrue()
        ->and($gate->allows($admin, Role::BanHang))->toBeTrue();
});

it('nhân viên không có Vai trò được yêu cầu bị từ chối bằng lỗi thiếu quyền', function () {
    $seller = User::factory()->create();
    $seller->assignRole(Role::BanHang);

    app(RoleGate::class)->authorize($seller, Role::NhapKho);
})->throws(MissingRole::class);

it('cho qua khi nhân viên có một trong các Vai trò được yêu cầu', function () {
    $stocker = User::factory()->create();
    $stocker->assignRole(Role::NhapKho);

    expect(fn () => app(RoleGate::class)->authorize($stocker, Role::NhapKho, Role::BanHang))
        ->not->toThrow(MissingRole::class);
});
