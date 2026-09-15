<?php

namespace App\Inventory\Intake;

use App\Models\Supplier;
use Carbon\CarbonImmutable;

/**
 * Lô nhập nhân viên gửi đi kiểm tra: thông tin chứng từ và các Dòng nhập.
 */
final readonly class BatchDraft
{
    /**
     * @param  list<BatchLineDraft>  $lines
     */
    public function __construct(
        public Supplier $supplier,
        public CarbonImmutable $receivedOn,
        public array $lines,
        public ?string $documentNumber = null,
        public ?string $note = null,
    ) {}
}
