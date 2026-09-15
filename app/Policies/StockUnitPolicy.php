<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\StockUnit;
use App\Models\User;

/**
 * Đơn vị hàng: mọi Vai trò xem danh sách ở dạng che; chỉ tạo qua nhập hàng.
 */
class StockUnitPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    public function view(User $user, StockUnit $stockUnit): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockUnit $stockUnit): bool
    {
        return false;
    }

    public function delete(User $user, StockUnit $stockUnit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockUnit $stockUnit): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StockUnit $stockUnit): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
