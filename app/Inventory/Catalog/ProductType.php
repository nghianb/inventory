<?php

namespace App\Inventory\Catalog;

use App\Inventory\Encryption\Normalization;

/**
 * Loại Sản phẩm: mỗi Đơn vị hàng là một Mã dùng một lần hoặc một Tài khoản.
 */
enum ProductType: string
{
    case OneTimeCode = 'one-time-code';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::OneTimeCode => 'Mã dùng một lần',
            self::Account => 'Tài khoản',
        };
    }

    /**
     * CD key, code thường được viết khác nhau về hoa thường, gạch ngang, khoảng trắng;
     * định danh đăng nhập (email, username) chỉ khác hoa thường, ký tự bên trong có nghĩa.
     */
    public function defaultNormalization(): Normalization
    {
        return match ($this) {
            self::OneTimeCode => new Normalization(caseInsensitive: true, stripSeparators: true),
            self::Account => new Normalization(caseInsensitive: true, stripSeparators: false),
        };
    }
}
