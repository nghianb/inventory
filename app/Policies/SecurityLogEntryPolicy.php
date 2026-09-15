<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\SecurityLogEntry;
use App\Models\User;

/**
 * Nhật ký bảo mật: chỉ Quản trị xem; không ai tạo, sửa, xoá qua giao diện.
 */
class SecurityLogEntryPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function view(User $user, SecurityLogEntry $entry): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SecurityLogEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, SecurityLogEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
