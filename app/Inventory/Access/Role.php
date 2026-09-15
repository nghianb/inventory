<?php

namespace App\Inventory\Access;

/**
 * Vai trò của nhân viên. Giá trị là tên role trong spatie/laravel-permission.
 */
enum Role: string
{
    case QuanTri = 'quan-tri';
    case NhapKho = 'nhap-kho';
    case BanHang = 'ban-hang';

    public function label(): string
    {
        return match ($this) {
            self::QuanTri => 'Quản trị',
            self::NhapKho => 'Nhập kho',
            self::BanHang => 'Bán hàng',
        };
    }
}
