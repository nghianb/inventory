<?php

namespace App\Inventory\Reports;

use InvalidArgumentException;
use stdClass;

/**
 * Một dòng báo cáo Tỉ lệ lỗi: một Nhà cung cấp × Sản phẩm, hoặc dòng tổng của một Nhà cung cấp
 * (`$productId` rỗng). Đếm theo Đơn vị hàng, trên lứa nhập của khoảng đang xem.
 */
final readonly class DefectRateReportRow
{
    /**
     * @param  ?int  $productId  rỗng thì đây là dòng tổng của Nhà cung cấp
     * @param  int  $intakeUnits  Đơn vị hàng nhập trong kỳ, không tính hàng đã Huỷ nhập
     * @param  int  $deliveredUnits  trong đó đã giao ít nhất một Slot: mẫu số của Tỉ lệ lỗi
     * @param  int  $defectiveUnits  trong số đã giao, Đơn vị hàng đang Lỗi: tử số của Tỉ lệ lỗi
     * @param  int  $defectiveInStockUnits  Đơn vị hàng Lỗi chưa giao Slot nào; tham khảo, ngoài Tỉ lệ lỗi
     * @param  int  $rejectedLines  số dòng bị bỏ vì lỗi định dạng hoặc trùng khi nhập: chất lượng file nhập
     */
    public function __construct(
        public int $supplierId,
        public string $supplierName,
        public ?int $productId,
        public ?string $code,
        public ?string $name,
        public int $intakeUnits,
        public int $deliveredUnits,
        public int $defectiveUnits,
        public int $defectiveInStockUnits,
        public int $rejectedLines,
    ) {}

    /**
     * Từ một dòng của {@see DefectRateReport::aggregates()}.
     */
    public static function fromRecord(stdClass $record): self
    {
        $count = fn (string $column): int => (int) $record->{$column};

        return new self(
            supplierId: (int) $record->supplier_id,
            supplierName: (string) $record->supplier_name,
            productId: (int) $record->product_id,
            code: (string) $record->code,
            name: (string) $record->product_name,
            intakeUnits: $count('intake_units'),
            deliveredUnits: $count('delivered_units'),
            defectiveUnits: $count('defective_units'),
            defectiveInStockUnits: $count('defective_in_stock_units'),
            rejectedLines: $count('rejected_lines'),
        );
    }

    /**
     * Dòng tổng của một Nhà cung cấp: cộng số Đơn vị hàng của mọi Sản phẩm rồi mới chia, chứ không lấy
     * trung bình các tỉ lệ, để Sản phẩm bán nhiều có trọng số đúng.
     *
     * @param  non-empty-list<self>  $rows  các dòng Sản phẩm của cùng một Nhà cung cấp
     */
    public static function totalOf(array $rows): self
    {
        $sum = fn (string $property): int => (int) array_sum(array_column($rows, $property));

        return new self(
            supplierId: $rows[0]->supplierId,
            supplierName: $rows[0]->supplierName,
            productId: null,
            code: null,
            name: null,
            intakeUnits: $sum('intakeUnits'),
            deliveredUnits: $sum('deliveredUnits'),
            defectiveUnits: $sum('defectiveUnits'),
            defectiveInStockUnits: $sum('defectiveInStockUnits'),
            rejectedLines: $sum('rejectedLines'),
        );
    }

    public function isTotal(): bool
    {
        return $this->productId === null;
    }

    /**
     * Tỉ lệ lỗi, hoặc rỗng khi chưa giao Đơn vị hàng nào của lứa nhập: chưa có gì để so sánh.
     */
    public function defectRate(): ?float
    {
        return $this->deliveredUnits === 0 ? null : $this->defectiveUnits / $this->deliveredUnits;
    }

    /**
     * Khoá dòng, để panel nhận ra từng dòng của bảng.
     */
    public function key(): string
    {
        return "nha-cung-cap-{$this->supplierId}-".($this->productId === null ? 'tong' : "san-pham-{$this->productId}");
    }

    /**
     * Giá trị theo khoá cột của {@see DefectRateReport::columns()}.
     */
    public function cell(string $column): string|int
    {
        return match ($column) {
            'supplier' => $this->supplierName,
            'code' => $this->code ?? 'Tổng',
            'name' => $this->name ?? '',
            'intake_units' => $this->intakeUnits,
            'delivered_units' => $this->deliveredUnits,
            'defective_units' => $this->defectiveUnits,
            'defect_rate' => self::percentage($this->defectRate()),
            'defective_in_stock_units' => $this->defectiveInStockUnits,
            'rejected_lines' => $this->rejectedLines,
            default => throw new InvalidArgumentException("Báo cáo Tỉ lệ lỗi không có cột {$column}."),
        };
    }

    /**
     * Tỉ lệ dạng chữ; chưa giao Đơn vị hàng nào thì để trống chứ không hiện 0%.
     */
    public static function percentage(?float $rate): string
    {
        return $rate === null ? '' : number_format($rate * 100, 1, ',', '.').'%';
    }
}
