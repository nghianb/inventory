<?php

namespace App\Inventory\Intake;

use Carbon\CarbonImmutable;

/**
 * Giá trị một Dòng nhập áp cho mọi Đơn vị hàng của nó, đã gộp tầng Sản phẩm → Dòng nhập.
 * Cột file ghi đè từng dòng.
 */
final readonly class LineDefaults
{
    public function __construct(
        public CarbonImmutable $receivedOn,
        public int $unitCost,
        public int $slots,
        public ?CarbonImmutable $expiresOn,
    ) {}
}
