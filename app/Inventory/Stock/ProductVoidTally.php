<?php

namespace App\Inventory\Stock;

/**
 * Số lượng của một lần Huỷ hàng hàng loạt theo Sản phẩm: trước khi huỷ là dự kiến, sau đó là số thực.
 */
final readonly class ProductVoidTally
{
    /**
     * @param  int  $voidedUnits  Đơn vị hàng Hoạt động chuyển sang Đã huỷ
     * @param  int  $voidedSlots  Slot Còn hàng của chúng chuyển sang Đã huỷ
     * @param  int  $keptUnits  Đơn vị hàng Hoạt động bỏ lại vì đang có Slot Đã giữ cho một Phiếu xuất
     */
    public function __construct(
        public int $voidedUnits,
        public int $voidedSlots,
        public int $keptUnits,
    ) {}
}
