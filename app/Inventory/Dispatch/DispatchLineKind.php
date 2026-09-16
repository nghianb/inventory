<?php

namespace App\Inventory\Dispatch;

/**
 * Loại Dòng xuất. Giao thay chỉ thêm dòng khi giao sang Sản phẩm khác; mỗi Đổi hàng thêm một dòng.
 * Cả hai loại ấy đều không có Giá bán: xem {@see allowsSalePrice()}.
 */
enum DispatchLineKind: string
{
    case Sale = 'sale';
    case Additional = 'additional';
    case Corrective = 'corrective';
    case Replacement = 'replacement';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Giao bán',
            self::Additional => 'Giao thêm',
            self::Corrective => 'Giao thay',
            self::Replacement => 'Đổi hàng',
        };
    }

    /**
     * Loại này có được ghi Giá bán không. Đổi hàng và Giao thay thì không: Chi phí đổi hàng trừ vào
     * Lãi ròng kho chứ không vào Lãi gộp của Phiếu xuất gốc, còn Slot Giao thay đã quy về Dòng xuất
     * gốc — doanh thu ghi ở đây sẽ không có Slot nào tương ứng trong phần Xuất của báo cáo.
     */
    public function allowsSalePrice(): bool
    {
        return $this === self::Sale || $this === self::Additional;
    }
}
