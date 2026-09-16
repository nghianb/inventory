<?php

namespace App\Inventory\Reports;

use InvalidArgumentException;
use stdClass;

/**
 * Một dòng báo cáo Lãi/lỗ: một Sản phẩm, dòng tổng, hoặc dòng 'Chưa có Giá bán'.
 *
 * Hai tầng tách bạch. Tầng trên là **Lãi gộp** của hàng đã bán: Doanh thu trừ Giá vốn hàng bán. Tầng
 * dưới là **Điều chỉnh**: các khoản phát sinh trong kho không gắn với một lần bán nào (Chi phí đổi
 * hàng, Tổn thất hàng Lỗi, Tổn thất Huỷ hàng theo từng lý do, Tổn thất hết hạn), trừ đi phần bồi hoàn
 * tiền đã đòi được. **Lãi ròng kho** = Lãi gộp − Điều chỉnh.
 */
final readonly class ProfitReportRow
{
    /**
     * @param  ?int  $productId  rỗng với dòng tổng và dòng 'Chưa có Giá bán'
     * @param  int  $revenue  tổng Giá bán các Dòng xuất tính vào kỳ
     * @param  int  $cogs  Giá vốn các Slot khách thực nhận của những Dòng xuất đó
     * @param  int  $replacementCost  Chi phí đổi hàng phát sinh trong kỳ
     * @param  int  $defectiveLoss  Giá vốn Slot còn trong kho mất đi khi Đơn vị hàng chuyển Lỗi
     * @param  int  $wrongDeliveryLoss  Tổn thất Huỷ hàng lý do giao nhầm
     * @param  int  $contentExposedLoss  Tổn thất Huỷ hàng lý do lộ nội dung
     * @param  int  $discontinuedLotLoss  Tổn thất Huỷ hàng lý do ngừng kinh doanh lô
     * @param  int  $expiryLoss  Giá vốn Slot còn trong kho quá Hạn sử dụng trong kỳ
     * @param  int  $reimbursement  bồi hoàn tiền từ Khiếu nại nhà cung cấp giải quyết trong kỳ
     * @param  int  $unpricedSlots  chỉ dòng 'Chưa có Giá bán': số Slot đã giao mà chưa biết giá
     */
    public function __construct(
        public ProfitRowKind $kind,
        public ?int $productId,
        public ?string $code,
        public ?string $name,
        public int $revenue,
        public int $cogs,
        public int $replacementCost = 0,
        public int $defectiveLoss = 0,
        public int $wrongDeliveryLoss = 0,
        public int $contentExposedLoss = 0,
        public int $discontinuedLotLoss = 0,
        public int $expiryLoss = 0,
        public int $reimbursement = 0,
        public int $unpricedSlots = 0,
    ) {}

    /**
     * Từ một dòng của {@see ProfitReport::aggregates()}. Cột vắng mặt đọc thành 0: bộ lọc quyết định
     * truy vấn con nào được nối, nên lọc Kênh bán thì các khoản Điều chỉnh không có trong bản ghi.
     */
    public static function fromRecord(stdClass $record): self
    {
        $money = fn (string $column): int => (int) ($record->{$column} ?? 0);

        return new self(
            kind: ProfitRowKind::Product,
            productId: (int) $record->product_id,
            code: (string) $record->code,
            name: (string) $record->product_name,
            revenue: $money('revenue'),
            cogs: $money('cogs'),
            replacementCost: $money('replacement_cost'),
            defectiveLoss: $money('defective_loss'),
            wrongDeliveryLoss: $money('wrong_delivery_loss'),
            contentExposedLoss: $money('content_exposed_loss'),
            discontinuedLotLoss: $money('discontinued_lot_loss'),
            expiryLoss: $money('expiry_loss'),
            reimbursement: $money('reimbursement'),
        );
    }

    /**
     * Dòng tổng của cả báo cáo: cộng từng khoản rồi mới tính lại Lãi gộp, % biên và Lãi ròng kho, chứ
     * không cộng các tỉ lệ, để Sản phẩm bán nhiều có trọng số đúng.
     *
     * @param  non-empty-list<self>  $rows  các dòng Sản phẩm
     */
    public static function totalOf(array $rows): self
    {
        $sum = fn (string $property): int => (int) array_sum(array_column($rows, $property));

        return new self(
            kind: ProfitRowKind::Total,
            productId: null,
            code: null,
            name: null,
            revenue: $sum('revenue'),
            cogs: $sum('cogs'),
            replacementCost: $sum('replacementCost'),
            defectiveLoss: $sum('defectiveLoss'),
            wrongDeliveryLoss: $sum('wrongDeliveryLoss'),
            contentExposedLoss: $sum('contentExposedLoss'),
            discontinuedLotLoss: $sum('discontinuedLotLoss'),
            expiryLoss: $sum('expiryLoss'),
            reimbursement: $sum('reimbursement'),
        );
    }

    /**
     * Dòng gom các Dòng xuất chưa ghi Giá bán: hàng đã rời kho nhưng chưa biết thu về bao nhiêu, nên
     * đứng ngoài Doanh thu và Lãi gộp. Giá vốn của chúng hiện ở cột Giá vốn hàng bán để thấy được phần
     * còn treo.
     */
    public static function unpriced(int $slots, int $cost): self
    {
        return new self(
            kind: ProfitRowKind::Unpriced,
            productId: null,
            code: null,
            name: null,
            revenue: 0,
            cogs: $cost,
            unpricedSlots: $slots,
        );
    }

    public function isTotal(): bool
    {
        return $this->kind === ProfitRowKind::Total;
    }

    public function isUnpriced(): bool
    {
        return $this->kind === ProfitRowKind::Unpriced;
    }

    public function grossProfit(): int
    {
        return $this->revenue - $this->cogs;
    }

    /**
     * Tổn thất Huỷ hàng của mọi lý do.
     */
    public function voidLoss(): int
    {
        return $this->wrongDeliveryLoss + $this->contentExposedLoss + $this->discontinuedLotLoss;
    }

    /**
     * Điều chỉnh: các khoản làm mỏng lãi mà không đi qua một lần bán nào, trừ đi bồi hoàn tiền đã đòi
     * được. Âm khi đòi được nhiều hơn phần mất đi trong kỳ.
     */
    public function adjustments(): int
    {
        return $this->replacementCost + $this->defectiveLoss + $this->voidLoss() + $this->expiryLoss - $this->reimbursement;
    }

    public function netProfit(): int
    {
        return $this->grossProfit() - $this->adjustments();
    }

    /**
     * Biên lãi gộp, hoặc rỗng khi chưa có doanh thu: không chia được cho 0.
     */
    public function margin(): ?float
    {
        return $this->revenue === 0 ? null : $this->grossProfit() / $this->revenue;
    }

    /**
     * Khoá dòng, để panel nhận ra từng dòng của bảng.
     */
    public function key(): string
    {
        return match ($this->kind) {
            ProfitRowKind::Product => "san-pham-{$this->productId}",
            ProfitRowKind::Total => 'tong',
            ProfitRowKind::Unpriced => 'chua-co-gia-ban',
        };
    }

    /**
     * Giá trị theo khoá cột của {@see ProfitReport::columns()}. Dòng 'Chưa có Giá bán' để trống mọi ô
     * nó không nói được: chưa biết Giá bán thì không có Lãi gộp, % biên hay Lãi ròng kho, và nó cũng
     * không mang khoản Điều chỉnh nào. Chỉ Giá vốn là có thật.
     */
    public function cell(string $column): string|int
    {
        $known = fn (int $value): string|int => $this->isUnpriced() ? '' : $value;

        return match ($column) {
            'code' => $this->label(),
            'name' => $this->isUnpriced() ? "{$this->unpricedSlots} Slot" : ($this->name ?? ''),
            'revenue' => $known($this->revenue),
            'cogs' => $this->cogs,
            'gross_profit' => $known($this->grossProfit()),
            'margin' => $this->isUnpriced() ? '' : self::percentage($this->margin()),
            'replacement_cost' => $known($this->replacementCost),
            'defective_loss' => $known($this->defectiveLoss),
            'wrong_delivery_loss' => $known($this->wrongDeliveryLoss),
            'content_exposed_loss' => $known($this->contentExposedLoss),
            'discontinued_lot_loss' => $known($this->discontinuedLotLoss),
            'expiry_loss' => $known($this->expiryLoss),
            'reimbursement' => $known($this->reimbursement),
            'adjustments' => $known($this->adjustments()),
            'net_profit' => $known($this->netProfit()),
            default => throw new InvalidArgumentException("Báo cáo Lãi/lỗ không có cột {$column}."),
        };
    }

    /**
     * Nhãn ở cột Mã sản phẩm: dòng tổng và dòng 'Chưa có Giá bán' tự xưng tên ở đó.
     */
    private function label(): string
    {
        return match ($this->kind) {
            ProfitRowKind::Product => (string) $this->code,
            ProfitRowKind::Total => 'Tổng',
            ProfitRowKind::Unpriced => 'Chưa có Giá bán',
        };
    }

    /**
     * Tỉ lệ dạng chữ; chưa có doanh thu thì để trống chứ không hiện 0%. Âm được khi bán dưới Giá vốn.
     */
    private static function percentage(?float $rate): string
    {
        return $rate === null ? '' : number_format($rate * 100, 1, ',', '.').'%';
    }
}
