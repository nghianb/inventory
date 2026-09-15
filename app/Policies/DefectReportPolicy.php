<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\DefectReport;
use App\Models\User;

/**
 * Báo lỗi: Quản trị và Bán hàng xem, tạo và xác minh; Nhập kho không thấy. Quy tắc thật nằm trong
 * DefectReporting. Báo lỗi chỉ tạo từ Phiếu xuất và không xoá.
 */
class DefectReportPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::BanHang);
    }

    public function view(User $user, DefectReport $defectReport): bool
    {
        return $this->roles->allows($user, Role::BanHang);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DefectReport $defectReport): bool
    {
        return false;
    }

    public function delete(User $user, DefectReport $defectReport): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, DefectReport $defectReport): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, DefectReport $defectReport): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
