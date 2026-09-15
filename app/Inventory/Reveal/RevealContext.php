<?php

namespace App\Inventory\Reveal;

use App\Models\Batch;

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
}
