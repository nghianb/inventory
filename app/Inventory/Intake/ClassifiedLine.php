<?php

namespace App\Inventory\Intake;

/**
 * Một dòng sau khi kiểm tra. Dòng nhập được (hợp lệ, nhập lại Tài khoản) mang giá trị các
 * trường (plaintext, chỉ sống trong bộ nhớ), hash Khoá chống trùng và giá trị đã gộp tầng.
 */
final readonly class ClassifiedLine
{
    /**
     * @param  array<string, string>  $values  theo định danh Trường nội dung, chỉ trường có giá trị
     * @param  ?string  $expiresOn  Hạn sử dụng, YYYY-MM-DD
     * @param  ?int  $renewsStockUnitId  Đơn vị hàng cũ mà dòng nhập lại
     */
    public function __construct(
        public int $lineNumber,
        public LineClass $class,
        public ?string $reason = null,
        public array $values = [],
        public ?string $dedupeHash = null,
        public int $slots = 1,
        public ?string $expiresOn = null,
        public int $unitCost = 0,
        public ?int $renewsStockUnitId = null,
    ) {}

    public function isImportable(): bool
    {
        return $this->class === LineClass::Valid || $this->class === LineClass::Renewal;
    }

    /**
     * Dòng hợp lệ nhưng Khoá chống trùng đã có trong kho (lúc kiểm tra hoặc lúc ghi).
     */
    public function asStockDuplicate(): self
    {
        return new self($this->lineNumber, LineClass::StockDuplicate, 'Khoá chống trùng đã có trong kho.');
    }

    /**
     * Dòng trùng một dòng đứng trước trong cùng Lô nhập.
     */
    public function asFileDuplicate(string $reason): self
    {
        return new self($this->lineNumber, LineClass::FileDuplicate, $reason);
    }

    /**
     * Tài khoản đã có trong kho nhưng Đơn vị hàng cũ đã Huỷ hàng hoặc quá Hạn sử dụng.
     */
    public function asRenewalOf(int $stockUnitId): self
    {
        return new self(
            $this->lineNumber,
            LineClass::Renewal,
            null,
            $this->values,
            $this->dedupeHash,
            $this->slots,
            $this->expiresOn,
            $this->unitCost,
            $stockUnitId,
        );
    }
}
