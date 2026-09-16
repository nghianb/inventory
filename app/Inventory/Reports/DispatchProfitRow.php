<?php

namespace App\Inventory\Reports;

use InvalidArgumentException;
use stdClass;

/**
 * Một dòng chi tiết Lãi/lỗ theo Phiếu xuất.
 *
 * 'Chi phí đổi hàng phát sinh' là cột **tham khảo**: nó cho thấy phiếu này về sau tốn thêm bao nhiêu
 * hàng để bù cho khách, nhưng không trừ vào Lãi gộp của phiếu — Chi phí đổi hàng chỉ trừ vào Lãi ròng
 * kho, ở tầng Điều chỉnh của báo cáo theo Sản phẩm.
 */
final readonly class DispatchProfitRow
{
    public function __construct(
        public int $dispatchId,
        public string $externalRef,
        public string $channelName,
        public int $salePrice,
        public int $cogs,
        public int $replacementCost,
    ) {}

    /**
     * Từ một dòng của {@see DispatchProfitReport::aggregates()}.
     */
    public static function fromRecord(stdClass $record): self
    {
        return new self(
            dispatchId: (int) $record->dispatch_id,
            externalRef: (string) $record->external_ref,
            channelName: (string) $record->channel_name,
            salePrice: (int) $record->revenue,
            cogs: (int) $record->cogs,
            replacementCost: (int) $record->replacement_cost,
        );
    }

    public function grossProfit(): int
    {
        return $this->salePrice - $this->cogs;
    }

    /**
     * Khoá dòng, để panel nhận ra từng dòng của bảng.
     */
    public function key(): string
    {
        return "phieu-xuat-{$this->dispatchId}";
    }

    /**
     * Giá trị theo khoá cột của {@see DispatchProfitReport::columns()}.
     */
    public function cell(string $column): string|int
    {
        return match ($column) {
            'dispatch' => "#{$this->dispatchId}",
            'external_ref' => $this->externalRef,
            'channel' => $this->channelName,
            'sale_price' => $this->salePrice,
            'cogs' => $this->cogs,
            'gross_profit' => $this->grossProfit(),
            'replacement_cost' => $this->replacementCost,
            default => throw new InvalidArgumentException("Chi tiết Lãi/lỗ theo Phiếu xuất không có cột {$column}."),
        };
    }
}
