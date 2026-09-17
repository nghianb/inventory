<?php

namespace App\Inventory\Staff;

use App\Models\User;
use RuntimeException;

/**
 * Lỗi nghiệp vụ: thao tác sẽ khiến kho không còn Quản trị đang hoạt động nào.
 */
class LastActiveOwner extends RuntimeException
{
    public function __construct(public readonly User $owner)
    {
        parent::__construct('Kho phải luôn còn ít nhất một Quản trị đang hoạt động.');
    }
}
