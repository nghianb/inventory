<?php

namespace App\Inventory\Dispatch;

/**
 * Trạng thái một lần Giao hàng nhìn từ phía website, khi nó đọc lại phiếu cho trang đơn của khách.
 *
 * Gộp hai thứ kho phân biệt (trạng thái Slot, và lần giao đã bị thay chưa) thành một câu trả lời cho
 * đúng câu hỏi website cần: nội dung này còn là thứ khách đang dùng không. Website không biết tới
 * Huỷ hàng hay Báo lỗi, nên không trả thẳng trạng thái Slot ra ngoài.
 */
enum ApiDeliveryStatus: string
{
    /** Slot Đã giao và chưa bị thay: nội dung này là thứ khách đang dùng. */
    case Active = 'active';

    /** Đã bị Đổi hàng hoặc Giao thay: nội dung nằm ở lần giao thay thế, không trả lại lần này. */
    case Replaced = 'replaced';

    /** Slot đã Huỷ hàng mà không có lần giao nào thay thế. */
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Còn hiệu lực',
            self::Replaced => 'Đã được thay',
            self::Voided => 'Đã huỷ',
        };
    }
}
