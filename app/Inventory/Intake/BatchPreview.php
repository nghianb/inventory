<?php

namespace App\Inventory\Intake;

/**
 * Màn xem trước của một Lô nhập.
 */
final readonly class BatchPreview
{
    /**
     * @param  list<BatchLinePreview>  $lines
     * @param  ?int  $invoiceTotal  tổng tiền hoá đơn nhân viên nhập để đối chiếu, VND
     */
    public function __construct(
        public BatchStatus $status,
        public ?string $validationError,
        public array $lines,
        public ?int $invoiceTotal = null,
        public ?int $supplementsBatchId = null,
    ) {}

    /**
     * Số Đơn vị hàng vào kho khi xác nhận.
     */
    public function importCount(): int
    {
        return array_sum(array_map(fn (BatchLinePreview $line): int => $line->importCount(), $this->lines));
    }

    public function stockDuplicateCount(): int
    {
        return array_sum(array_map(fn (BatchLinePreview $line): int => $line->stockDuplicateCount, $this->lines));
    }

    public function totalCost(): int
    {
        return array_sum(array_map(fn (BatchLinePreview $line): int => $line->totalCost, $this->lines));
    }

    /**
     * Tổng tiền hoá đơn trừ tổng Giá vốn phần nhập được; null khi không nhập tổng tiền hoá đơn.
     */
    public function invoiceDifference(): ?int
    {
        return $this->invoiceTotal === null ? null : $this->invoiceTotal - $this->totalCost();
    }
}
