<?php

namespace App\Inventory\Reveal;

/**
 * Loại Ngữ cảnh xem mã. Mỗi màn xem nội dung sau này (Giao hàng, Đổi hàng, Báo lỗi, Khiếu nại
 * nhà cung cấp) thêm loại của nó cùng quy tắc quyền trong ContentReveal.
 */
enum RevealContextType: string
{
    /** Quản trị xem hàng Còn hàng kèm lý do tự do; không có bản ghi ngữ cảnh. */
    case InStock = 'in-stock';

    /** Tải dòng bị bỏ khi nhập của một Lô nhập. */
    case Batch = 'batch';

    /** Một lần Giao hàng: màn kết quả xuất kho, xem lại mã của lần giao. */
    case Delivery = 'delivery';

    /** Xác minh một Báo lỗi Chờ xác minh. */
    case DefectReport = 'defect-report';

    /** Màn kết quả ngay sau khi Đổi hàng. */
    case Replacement = 'replacement';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'Quản trị xem hàng Còn hàng',
            self::Batch => 'Lô nhập',
            self::Delivery => 'Giao hàng',
            self::DefectReport => 'Báo lỗi',
            self::Replacement => 'Đổi hàng',
        };
    }
}
