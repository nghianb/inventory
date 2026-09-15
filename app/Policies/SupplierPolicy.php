<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Supplier;
use App\Models\User;

/**
 * Nhà cung cấp: Quản trị và Nhập kho quản lý; Bán hàng không thấy.
 */
class SupplierPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
