<?php

namespace App\Inventory\Security;

use LogicException;

class AppendOnlyViolation extends LogicException
{
    public static function for(string $model): self
    {
        return new self("{$model} chỉ-ghi-thêm, không được sửa hay xoá.");
    }
}
