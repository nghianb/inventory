<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\User;

/**
 * Nhân viên: chỉ Quản trị xem và tạo. Nhân viên không bao giờ bị xoá vì các
 * nhật ký tham chiếu tới họ; thay vào đó dùng Khoá nhân viên.
 */
class UserPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function view(User $user, User $staff): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function update(User $user, User $staff): bool
    {
        return false;
    }

    public function delete(User $user, User $staff): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $staff): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $staff): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
