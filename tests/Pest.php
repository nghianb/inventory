<?php

use App\Inventory\Access\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Nhân viên đã bật 2FA, mang các Vai trò cho trước. Cần chạy RoleSeeder trước.
 */
function staffMember(Role ...$roles): User
{
    return tap(User::factory()->withTwoFactor()->create())->assignRole($roles);
}
