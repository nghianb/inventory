<?php

namespace Database\Seeders;

use App\Inventory\Access\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Khai báo ba Vai trò. Chạy lại nhiều lần vẫn an toàn.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web');
        }
    }
}
