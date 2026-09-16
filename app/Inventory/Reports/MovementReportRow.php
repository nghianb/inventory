<?php

namespace App\Inventory\Reports;

use App\Models\Product;
use InvalidArgumentException;

/**
 * Một dòng báo cáo Nhập/xuất: một Sản phẩm, đếm theo Slot trong khoảng ngày.
 */
final readonly class MovementReportRow
{
    /**
     * @param  int  $inSlots  Slot vào kho theo ngày xác nhận Lô nhập, không tính hàng đã Huỷ nhập
     * @param  int  $inReplacementSlots  phần của `$inSlots` là hàng thay thế từ Khiếu nại nhà cung cấp
     * @param  int  $inCost  tổng Giá vốn Slot vào kho
     * @param  int  $soldSlots  Slot Giao bán, gồm cả Giao thêm
     * @param  int  $replacementSlots  Slot giao ra trong Đổi hàng
     * @param  int  $correctiveSlots  Slot giao ra trong Giao thay
     * @param  int  $outCost  tổng Giá vốn Slot đã giao
     * @param  int  $saleTotal  tổng Giá bán các Dòng xuất có lần Giao hàng đầu trong khoảng
     * @param  int  $voidedSlots  Slot Huỷ hàng trong khoảng
     * @param  int  $defectiveSlots  Slot còn trong kho chuyển thành Tồn lỗi trong khoảng
     */
    public function __construct(
        public int $productId,
        public string $code,
        public string $name,
        public int $inSlots,
        public int $inReplacementSlots,
        public int $inCost,
        public int $soldSlots,
        public int $replacementSlots,
        public int $correctiveSlots,
        public int $outCost,
        public int $saleTotal,
        public int $voidedSlots,
        public int $defectiveSlots,
    ) {}

    /**
     * Từ một Sản phẩm lấy qua {@see MovementReport::query()}.
     */
    public static function fromProduct(Product $product): self
    {
        $count = fn (string $column): int => (int) $product->getAttribute($column);

        return new self(
            productId: (int) $product->getKey(),
            code: $product->code,
            name: $product->name,
            inSlots: $count('in_slots'),
            inReplacementSlots: $count('in_replacement_slots'),
            inCost: $count('in_cost'),
            soldSlots: $count('sold_slots'),
            replacementSlots: $count('replacement_slots'),
            correctiveSlots: $count('corrective_slots'),
            outCost: $count('out_cost'),
            saleTotal: $count('sale_total'),
            voidedSlots: $count('voided_slots'),
            defectiveSlots: $count('defective_slots'),
        );
    }

    /**
     * Giá trị theo khoá cột của {@see MovementReport::columns()}.
     */
    public function cell(string $column): string|int
    {
        return match ($column) {
            'code' => $this->code,
            'name' => $this->name,
            'in_slots' => $this->inSlots,
            'in_replacement_slots' => $this->inReplacementSlots,
            'in_cost' => $this->inCost,
            'sold_slots' => $this->soldSlots,
            'replacement_slots' => $this->replacementSlots,
            'corrective_slots' => $this->correctiveSlots,
            'out_cost' => $this->outCost,
            'sale_total' => $this->saleTotal,
            'voided_slots' => $this->voidedSlots,
            'defective_slots' => $this->defectiveSlots,
            default => throw new InvalidArgumentException("Báo cáo Nhập/xuất không có cột {$column}."),
        };
    }
}
