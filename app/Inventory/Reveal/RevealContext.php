<?php

namespace App\Inventory\Reveal;

use App\Models\Batch;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Replacement;

/**
 * Ngữ cảnh xem mã: bản ghi mà qua đó nội dung đầy đủ được hiển thị hoặc tải về.
 */
final readonly class RevealContext
{
    private function __construct(
        public RevealContextType $type,
        public ?int $id,
    ) {}

    public static function inStock(): self
    {
        return new self(RevealContextType::InStock, null);
    }

    public static function batch(Batch $batch): self
    {
        return new self(RevealContextType::Batch, $batch->getKey());
    }

    public static function delivery(Delivery $delivery): self
    {
        return new self(RevealContextType::Delivery, $delivery->getKey());
    }

    public static function defectReport(DefectReport $report): self
    {
        return new self(RevealContextType::DefectReport, $report->getKey());
    }

    public static function replacement(Replacement $replacement): self
    {
        return new self(RevealContextType::Replacement, $replacement->getKey());
    }
}
