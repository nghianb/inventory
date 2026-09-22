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

    /**
     * Ghi Tổng tiền hoá đơn: ngoại lệ cố ý duy nhất sửa được sau khi xác nhận (ADR 0006).
     * Đi đường riêng thay vì mở update, vốn sẽ bật affordance sửa mặc định của Filament.
     */
    public function recordInvoiceTotal(User $user, Batch $batch): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    /**
     * Huỷ nhập: chỉ Quản trị.
     */
    public function reverse(User $user, Batch $batch): bool
    {
        return $this->roles->allows($user);
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
