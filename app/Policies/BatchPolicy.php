<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Batch;
use App\Models\User;

/**
 * Lô nhập: Quản trị và Nhập kho tạo, xem trước và xác nhận; Bán hàng không thấy.
 * Quy tắc thật nằm trong BatchIntake. Lô nhập không sửa, không xoá.
 */
class BatchPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function view(User $user, Batch $batch): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function confirm(User $user, Batch $batch): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    public function update(User $user, Batch $batch): bool
    {
        return false;
    }

    public function delete(User $user, Batch $batch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Batch $batch): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Batch $batch): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
