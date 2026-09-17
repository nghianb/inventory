<?php

namespace App\Inventory\Dispatch;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: kho đang Tạm dừng xuất kho nên không Giữ hàng hay Giao hàng được, bất kể Kênh bán
 * nào. Nhập hàng, Huỷ hàng và Ghi nhận giao bù không ném lỗi này: chúng không lấy thêm hàng ra khỏi
 * kho.
 */
class DispatchFrozen extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Kho đang Tạm dừng xuất kho nên không Giữ hàng hay Giao hàng được. Lý do: {$reason}");
    }
}
