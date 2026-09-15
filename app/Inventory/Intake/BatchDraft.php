<?php

namespace App\Inventory\Intake;

use App\Models\Batch;
use App\Models\Supplier;
use App\Models\SupplierClaim;
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
     * @param  ?SupplierClaim  $supplierClaim  Khiếu nại nhà cung cấp mà lô này là hàng thay thế (Giá vốn 0)
     */
    public function __construct(
        public Supplier $supplier,
        public CarbonImmutable $receivedOn,
        public array $lines,
        public ?string $documentNumber = null,
        public ?string $note = null,
        public ?int $invoiceTotal = null,
        public ?Batch $supplements = null,
        public ?SupplierClaim $supplierClaim = null,
    ) {}
}
