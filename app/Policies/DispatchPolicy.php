<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Dispatch;
use App\Models\User;

/**
 * Phiếu xuất: Quản trị và Bán hàng tạo, xem và sửa phiếu Hoàn tất; Nhập kho không thấy. Quy tắc
 * thật nằm trong ManualDispatch và DispatchEditor. Phiếu xuất không xoá.
 */
class DispatchPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::BanHang);
    }

    public function view(User $user, Dispatch $dispatch): bool
    {
        return $this->roles->allows($user, Role::BanHang);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::BanHang);
    }

    public function update(User $user, Dispatch $dispatch): bool
    {
        return $this->roles->allows($user, Role::BanHang);
    }

    public function delete(User $user, Dispatch $dispatch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Dispatch $dispatch): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Dispatch $dispatch): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
