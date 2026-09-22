<?php

namespace App\Inventory\Intake;

use App\Models\BatchLine;

/**
 * Giá trị áp cho Đơn vị hàng mới của một Dòng nhập. Cùng ba giá trị mà {@see BatchLineDraft}
 * mang, nên cột ghi đè trong file nhập vẫn thắng chúng như lần kiểm tra đầu.
 */
final readonly class BatchLineRevision
{
    /**
     * @param  int  $unitCost  Giá vốn mỗi Đơn vị hàng, VND
     * @param  ?int  $slots  số slot mỗi Tài khoản; null thì theo Sản phẩm
     */
    public function __construct(
        public BatchLine $line,
        public int $unitCost,
        public ?int $slots = null,
        public ?ExpiryRule $expiry = null,
    ) {}
}
