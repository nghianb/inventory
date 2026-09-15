<?php

namespace App\Inventory\Stock;

/**
 * Một lần chuyển trạng thái cần ghi vào Sổ biến động kho. `from` null nghĩa là vừa tạo.
 */
final readonly class StockTransition
{
    public function __construct(
        public int $stockUnitId,
        public ?int $slotId,
        public StockUnitStatus|SlotStatus|null $from,
        public StockUnitStatus|SlotStatus $to,
    ) {}

    public static function unitCreated(int $stockUnitId): self
    {
        return new self($stockUnitId, null, null, StockUnitStatus::Active);
    }

    public static function slotCreated(int $stockUnitId, int $slotId): self
    {
        return new self($stockUnitId, $slotId, null, SlotStatus::InStock);
    }
}
