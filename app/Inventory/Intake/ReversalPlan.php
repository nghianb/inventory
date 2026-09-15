<?php

namespace App\Inventory\Intake;

/**
 * Số lượng của một lần Huỷ nhập: trước khi Huỷ nhập là dự kiến, sau đó là số thực.
 */
final readonly class ReversalPlan
{
    /**
     * @param  int  $reversedUnits  Đơn vị hàng bị Huỷ nhập (mọi Slot còn Còn hàng)
     * @param  int  $reversedSlots  Slot của các Đơn vị hàng đó
     * @param  int  $keptUnits  Đơn vị hàng giữ lại vì có Slot đã giữ, đã giao hoặc không còn Hoạt động
     */
    public function __construct(
        public int $reversedUnits,
        public int $reversedSlots,
        public int $keptUnits,
    ) {}
}
