<?php

namespace App\Inventory\Intake;

/**
 * Màn xem trước của một Lô nhập.
 */
final readonly class BatchPreview
{
    /**
     * @param  list<BatchLinePreview>  $lines
     */
    public function __construct(
        public BatchStatus $status,
        public ?string $validationError,
        public array $lines,
    ) {}

    public function validCount(): int
    {
        return array_sum(array_map(fn (BatchLinePreview $line): int => $line->validCount, $this->lines));
    }

    public function totalCost(): int
    {
        return array_sum(array_map(fn (BatchLinePreview $line): int => $line->totalCost, $this->lines));
    }
}
