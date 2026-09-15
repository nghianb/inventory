<?php

namespace App\Inventory\Dispatch;

use Carbon\CarbonImmutable;

/**
 * Tóm tắt cho modal Giao thay: lần giao sẽ bị huỷ, hạn Bán hàng còn Giao thay được và Lần giao bị
 * ảnh hưởng nếu chọn Huỷ hàng cả Đơn vị hàng.
 */
final readonly class CorrectionPreview
{
    /**
     * @param  list<AffectedDelivery>  $affected
     */
    public function __construct(
        public string $productName,
        public string $unitLabel,
        public CarbonImmutable $deliveredAt,
        public CarbonImmutable $deadline,
        public bool $late,
        public array $affected,
    ) {}

    /**
     * "Còn 3 giờ 05 phút" hoặc "Đã quá hạn lúc 16/09/2026 10:00".
     */
    public function remainingLabel(): string
    {
        if ($this->late) {
            return 'Đã quá hạn lúc '.$this->deadline->format('d/m/Y H:i');
        }

        $minutes = (int) floor(CarbonImmutable::now()->diffInMinutes($this->deadline));

        return sprintf('Còn %d giờ %02d phút', intdiv($minutes, 60), $minutes % 60);
    }
}
