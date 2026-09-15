<?php

namespace App\Inventory\Intake;

/**
 * Kết quả kiểm tra một dòng nhập.
 */
enum LineClass: string
{
    case Valid = 'valid';
    case Renewal = 'renewal';
    case Invalid = 'invalid';
    case FileDuplicate = 'file-duplicate';
    case StockDuplicate = 'stock-duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Hợp lệ',
            self::Renewal => 'Nhập lại Tài khoản hợp lệ',
            self::Invalid => 'Lỗi định dạng',
            self::FileDuplicate => 'Trùng trong file',
            self::StockDuplicate => 'Trùng trong kho',
        };
    }
}
