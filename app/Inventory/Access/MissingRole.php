<?php

namespace App\Inventory\Access;

use App\Models\User;
use RuntimeException;

/**
 * Lỗi nghiệp vụ: nhân viên không mang Vai trò cần cho thao tác.
 */
class MissingRole extends RuntimeException
{
    /**
     * @param  list<Role>  $required
     */
    public function __construct(public readonly User $user, public readonly array $required)
    {
        $labels = implode(', ', array_map(fn (Role $role): string => $role->label(), $required));

        parent::__construct("Thao tác cần Vai trò: {$labels}.");
    }
}
