<?php

namespace App\Inventory\Intake;

/**
 * Kết quả kiểm tra một Dòng nhập. Trước khi xác nhận là kết quả pha 1; sau khi xác nhận
 * là số thực ghi vào kho.
 */
final readonly class BatchLinePreview
{
    /**
     * @param  list<RejectedLine>  $rejected
     * @param  list<array<string, string>>  $sample  vài Đơn vị hàng hợp lệ ở dạng che
     * @param  int  $totalCost  tổng Giá vốn phần hợp lệ, VND
     */
    public function __construct(
        public string $productName,
        public int $validCount,
        public int $invalidCount,
        public int $fileDuplicateCount,
        public int $stockDuplicateCount,
        public array $rejected,
        public array $sample,
        public int $totalCost,
    ) {}
}
