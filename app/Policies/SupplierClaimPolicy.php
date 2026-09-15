<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\SupplierClaim;
use App\Models\User;

/**
 * Khiếu nại nhà cung cấp: Quản trị và Nhập kho tạo, gửi, giải quyết và huỷ; Bán hàng không thấy.
 * Quy tắc thật nằm trong SupplierClaims. Khiếu nại không sửa trực tiếp, không xoá.
 */
class SupplierClaimPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function view(User $user, SupplierClaim $supplierClaim): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function update(User $user, SupplierClaim $supplierClaim): bool
    {
        return false;
    }

    public function delete(User $user, SupplierClaim $supplierClaim): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SupplierClaim $supplierClaim): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SupplierClaim $supplierClaim): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
