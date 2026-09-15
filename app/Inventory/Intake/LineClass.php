<?php

namespace App\Inventory\Intake;

/**
 * Kết quả kiểm tra một dòng dán.
 */
enum LineClass: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case FileDuplicate = 'file-duplicate';
    case StockDuplicate = 'stock-duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Hợp lệ',
            self::Invalid => 'Lỗi định dạng',
            self::FileDuplicate => 'Trùng trong file',
            self::StockDuplicate => 'Trùng trong kho',
        };
    }
}
