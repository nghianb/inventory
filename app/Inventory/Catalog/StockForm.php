<?php

namespace App\Inventory\Catalog;

use App\Inventory\Encryption\Normalization;
use App\Models\ProductType;

/**
 * Dạng hàng: hàng của một Loại sản phẩm nằm trong kho dưới hình thức nào — Mã dùng một lần
 * hay Tài khoản. Quyết định một Đơn vị hàng chia được mấy Slot.
 *
 * Không phải Loại sản phẩm: đó là {@see ProductType}, khuôn khai Trường nội dung
 * dùng chung cho nhiều Sản phẩm.
 */
enum StockForm: string
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
