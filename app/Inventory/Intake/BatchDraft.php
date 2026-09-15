<?php

namespace App\Inventory\Intake;

use App\Models\Batch;
use App\Models\Supplier;
use Carbon\CarbonImmutable;

/**
 * Lô nhập nhân viên gửi đi kiểm tra: thông tin chứng từ và các Dòng nhập.
 */
final readonly class BatchDraft
{
    /**
     * @param  list<BatchLineDraft>  $lines
     * @param  ?int  $invoiceTotal  tổng tiền hoá đơn (VND) để đối chiếu với tổng Giá vốn
     * @param  ?Batch  $supplements  Lô nhập đã xác nhận mà lô này bổ sung
     */
    public function __construct(
        public Supplier $supplier,
        public CarbonImmutable $receivedOn,
        public array $lines,
        public ?string $documentNumber = null,
        public ?string $note = null,
        public ?int $invoiceTotal = null,
        public ?Batch $supplements = null,
    ) {}
}
