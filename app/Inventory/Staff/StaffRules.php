<?php

namespace App\Inventory\Staff;

use Illuminate\Validation\Rules\Password;

/**
 * Luật chung cho dữ liệu nhân viên. Trang Nhân viên và lệnh tạo Quản trị đầu tiên cùng
 * đọc ở đây, nên siết chính sách mật khẩu chỉ phải sửa một chỗ: Quản trị đầu tiên không
 * bao giờ là ngoại lệ dễ dãi hơn nhân viên tạo từ panel.
 */
class StaffRules
{
    /**
     * Giới hạn cho Tên và Email.
     */
    public const MAX_LENGTH = 255;

    /**
     * Chính sách mật khẩu cho mọi nhân viên.
     */
    public static function password(): Password
    {
        return Password::default();
    }
}
