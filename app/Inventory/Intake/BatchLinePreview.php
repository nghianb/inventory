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
     * @param  list<array<string, string>>  $sample  vài Đơn vị hàng nhập được ở dạng che
     * @param  list<string>  $ignoredColumns  cột file bị bỏ qua
     * @param  int  $totalCost  tổng Giá vốn phần nhập được (hợp lệ và nhập lại), VND
     */
    public function __construct(
        public string $productName,
        public IntakeSource $source,
        public ?string $fileName,
        public int $validCount,
        public int $renewalCount,
        public int $invalidCount,
        public int $fileDuplicateCount,
        public int $stockDuplicateCount,
        public array $rejected,
        public array $sample,
        public array $ignoredColumns,
        public int $totalCost,
    ) {}

    /**
     * Số Đơn vị hàng vào kho khi xác nhận.
     */
    public function importCount(): int
    {
        return $this->validCount + $this->renewalCount;
    }
}
