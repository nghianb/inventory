<?php

namespace App\Policies;

use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Product;
use App\Models\User;

/**
 * Sản phẩm: mọi Vai trò xem được; chỉ Quản trị cấu hình, Ngừng bán và xoá.
 * Quy tắc thật (khoá cấu hình, chặn xoá khi có hàng) nằm trong ProductCatalog.
 */
class ProductPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->roles->allows($user, Role::QuanTri);
    }

    public function discontinue(User $user, Product $product): bool
    {
        return $this->roles->allows($user, Role::QuanTri) && ! $product->isDiscontinued();
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->roles->allows($user, Role::QuanTri) && ! $product->hasStock();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Product $product): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
