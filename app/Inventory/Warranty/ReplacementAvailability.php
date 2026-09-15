<?php

namespace App\Inventory\Warranty;

/**
 * Hàng để Đổi hàng của một Sản phẩm: có Slot có Hạn sử dụng phủ Hạn bảo hành kế thừa, chỉ có Slot hạn
 * ngắn hơn (Đổi hàng phải chấp nhận sau cảnh báo), hoặc hết hàng.
 */
enum ReplacementAvailability
{
    case Covering;
    case ShorterOnly;
    case OutOfStock;
}
