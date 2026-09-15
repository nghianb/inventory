<?php

namespace App\Inventory\Intake;

/**
 * Một dòng dán sau khi kiểm tra. Dòng hợp lệ mang giá trị các trường (plaintext, chỉ
 * sống trong bộ nhớ) và hash Khoá chống trùng.
 */
final readonly class ClassifiedLine
{
    /**
     * @param  array<string, string>  $values  theo định danh Trường nội dung, chỉ trường có giá trị
     */
    public function __construct(
        public int $lineNumber,
        public LineClass $class,
        public ?string $reason = null,
        public array $values = [],
        public ?string $dedupeHash = null,
    ) {}

    /**
     * Dòng hợp lệ nhưng Khoá chống trùng đã có trong kho (lúc kiểm tra hoặc lúc ghi).
     */
    public function asStockDuplicate(): self
    {
        return new self($this->lineNumber, LineClass::StockDuplicate, 'Khoá chống trùng đã có trong kho.');
    }
}
