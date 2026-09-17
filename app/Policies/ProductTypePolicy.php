<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\ProductType;
use App\Models\User;

/**
 * Loại sản phẩm: mọi Vai trò xem được; chỉ Quản trị khai báo, Ngừng dùng và xoá.
 * Quy tắc thật (từ chối trọn gói khi đã có hàng, chặn xoá khi có Sản phẩm) nằm trong
 * ProductTypeCatalog.
 */
class ProductTypePolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    public function view(User $user, ProductType $type): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::Owner);
    }

    public function update(User $user, ProductType $type): bool
    {
        return $this->roles->allows($user, Role::Owner);
    }

    public function discontinue(User $user, ProductType $type): bool
    {
        return $this->roles->allows($user, Role::Owner) && ! $type->isDiscontinued();
    }

    public function delete(User $user, ProductType $type): bool
    {
        return $this->roles->allows($user, Role::Owner) && $type->products()->doesntExist();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductType $type): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ProductType $type): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
