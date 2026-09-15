<?php

namespace App\Inventory\Access;

use App\Models\User;

/**
 * Chỗ duy nhất module Kho kiểm tra quyền theo Vai trò. Quản trị làm được mọi việc;
 * không có quyền lẻ theo từng nhân viên. Policy của Laravel gọi vào đây.
 */
class RoleGate
{
    public function allows(User $user, Role ...$roles): bool
    {
        return $user->hasAnyRole([Role::QuanTri, ...$roles]);
    }

    /**
     * @throws MissingRole
     */
    public function authorize(User $user, Role ...$roles): void
    {
        if (! $this->allows($user, ...$roles)) {
            throw new MissingRole($user, array_values($roles));
        }
    }
}
