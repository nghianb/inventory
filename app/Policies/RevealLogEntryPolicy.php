<?php

namespace App\Policies;

use App\Inventory\Access\RoleGate;
use App\Models\RevealLogEntry;
use App\Models\User;

/**
 * Nhật ký xem mã: chỉ Quản trị xem; không ai tạo, sửa, xoá qua giao diện.
 */
class RevealLogEntryPolicy
{
    public function __construct(private RoleGate $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->roles->allows($user);
    }

    public function view(User $user, RevealLogEntry $entry): bool
    {
        return $this->roles->allows($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RevealLogEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, RevealLogEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
