<?php

namespace App\Inventory\Staff;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: kho đã có Quản trị nên không còn chỗ cho Quản trị đầu tiên.
 */
class QuanTriAlreadyExists extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Kho đã có Quản trị.');
    }
}
