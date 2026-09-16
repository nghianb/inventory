<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\ApiKey;
use App\Models\User;

/**
 * Khoá API: chỉ Quản trị thấy và quản lý. Khoá không bao giờ bị xoá (Nhật ký xem mã và Phiếu xuất
 * tham chiếu tới nó) và không sửa bằng form — tạo, xoay, thu hồi đều gọi ApiKeys.
 */
class ApiKeyPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function view(User $user, ApiKey $key): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function update(User $user, ApiKey $key): bool
    {
        return false;
    }

    public function delete(User $user, ApiKey $key): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ApiKey $key): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ApiKey $key): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
