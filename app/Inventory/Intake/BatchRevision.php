<?php

namespace App\Inventory\Intake;

use Carbon\CarbonImmutable;

/**
 * Lần sửa một Lô nhập còn Chờ xác nhận: phần chứng từ và Giá trị áp cho Đơn vị hàng của mọi
 * Dòng nhập. Nội dung (danh sách Đơn vị hàng), Nhà cung cấp và Sản phẩm không có ở đây: sửa
 * chúng là đổi chính thứ đã được kiểm tra, tức một Lô nhập khác.
 */
final readonly class BatchRevision
{
    /**
     * @param  list<BatchLineRevision>  $lines  đúng một lần cho mỗi Dòng nhập của Lô nhập
     */
    public function __construct(
        public CarbonImmutable $receivedOn,
        public array $lines,
        public ?string $documentNumber = null,
        public ?string $note = null,
    ) {}
}
