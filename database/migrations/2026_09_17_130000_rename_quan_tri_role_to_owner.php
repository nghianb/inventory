<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vai trò Quản trị đổi định danh trong code từ `quan-tri` sang `owner`. Bản ghi trong
 * bảng roles phải đổi theo: nhân viên nối với Vai trò qua khoá ngoại nên không mất gán,
 * nhưng mọi kiểm tra quyền đều so theo tên, nên bỏ qua bước này là cả kho mất Quản trị.
 */
return new class extends Migration
{
    public function up(): void
    {
        RoleModel::query()->where('name', 'quan-tri')->update(['name' => 'owner']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        RoleModel::query()->where('name', 'owner')->update(['name' => 'quan-tri']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
