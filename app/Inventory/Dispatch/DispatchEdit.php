<?php

namespace App\Inventory\Dispatch;

/**
 * Giá trị mới của mọi trường sửa được của một Phiếu xuất Hoàn tất.
 */
final readonly class DispatchEdit
{
    /**
     * @param  array<int, ?int>  $salePrices  Giá bán mới theo id Dòng xuất; dòng không có trong mảng giữ nguyên
     */
    public function __construct(
        public ?string $externalRef,
        public ?string $customer,
        public ?string $note,
        public array $salePrices = [],
    ) {}
}
