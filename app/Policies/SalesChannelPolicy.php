<?php

namespace App\Policies;

use App\Inventory\Access\RoleGate;
use App\Models\SalesChannel;
use App\Models\User;

/**
 * Kênh bán: chỉ Quản trị cấu hình. Form Phiếu xuất đọc danh sách kênh trực tiếp, không qua
 * trang này. Kênh không xoá, chỉ ẩn.
 */
class SalesChannelPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user);
    }

    public function view(User $user, SalesChannel $channel): bool
    {
        return $this->roles->allows($user);
    }

    public function create(User $user): bool
    {
        return $this->roles->allows($user);
    }

    public function update(User $user, SalesChannel $channel): bool
    {
        return $this->roles->allows($user);
    }

    public function delete(User $user, SalesChannel $channel): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, SalesChannel $channel): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SalesChannel $channel): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
