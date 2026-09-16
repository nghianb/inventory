<?php

namespace App\Inventory\Reports;

use InvalidArgumentException;
use stdClass;

/**
 * Một dòng báo cáo lỗ theo Nhà cung cấp: hàng của họ đã làm shop mất bao nhiêu, và đòi lại được bao
 * nhiêu.
 *
 * Chỉ gồm phần lỗi **thuộc về Nhà cung cấp**: Giá vốn hàng Lỗi và Chi phí đổi hàng phải bỏ ra để bù
 * cho khách. Tổn thất Huỷ hàng và Tổn thất hết hạn không vào đây — đó là chuyện của shop (giao nhầm,
 * lộ nội dung, ôm hàng quá lâu), không phải hàng nhà cung cấp giao sai.
 *
 * Bồi hoàn **bằng hàng** để ở cột tham khảo, không trừ vào Lỗ ròng: hàng thay thế vào kho với Giá vốn
 * 0 nên nó đã tự phản ánh khi bán, trừ thêm lần nữa là tính hai lần.
 */
final readonly class SupplierLossRow
{
    public function __construct(
        public int $supplierId,
        public string $supplierName,
        public int $defectiveLoss,
        public int $replacementCost,
        public int $reimbursement,
        public int $replacementGoodsUnits,
    ) {}

    /**
     * Từ một dòng của {@see SupplierLossReport::aggregates()}.
     */
    public static function fromRecord(stdClass $record): self
    {
        return new self(
            supplierId: (int) $record->supplier_id,
            supplierName: (string) $record->supplier_name,
            defectiveLoss: (int) $record->defective_loss,
            replacementCost: (int) $record->replacement_cost,
            reimbursement: (int) $record->reimbursement,
            replacementGoodsUnits: (int) $record->replacement_goods_units,
        );
    }

    /**
     * Lỗ ròng: phần mất đi vì hàng của Nhà cung cấp này, trừ đi tiền đã đòi được. Âm khi đòi được
     * nhiều hơn phần đã mất trong kỳ.
     */
    public function netLoss(): int
    {
        return $this->defectiveLoss + $this->replacementCost - $this->reimbursement;
    }

    /**
     * Khoá dòng, để panel nhận ra từng dòng của bảng.
     */
    public function key(): string
    {
        return "nha-cung-cap-{$this->supplierId}";
    }

    /**
     * Giá trị theo khoá cột của {@see SupplierLossReport::columns()}.
     */
    public function cell(string $column): string|int
    {
        return match ($column) {
            'supplier' => $this->supplierName,
            'defective_loss' => $this->defectiveLoss,
            'replacement_cost' => $this->replacementCost,
            'reimbursement' => $this->reimbursement,
            'replacement_goods_units' => $this->replacementGoodsUnits,
            'net_loss' => $this->netLoss(),
            default => throw new InvalidArgumentException("Báo cáo lỗ theo Nhà cung cấp không có cột {$column}."),
        };
    }
}
