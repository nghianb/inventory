<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Một lần Giao hàng: trao đúng một Slot theo một Dòng xuất.
 *
 * @property int $id
 * @property int $dispatch_line_id
 * @property int $slot_id
 * @property int $stock_unit_id
 * @property int $warranty_days thời hạn bảo hành của Sản phẩm tại thời điểm giao
 * @property CarbonImmutable $delivered_at
 * @property ?int $delivered_by
 * @property ?int $corrects_delivery_id lần giao bị huỷ mà lần giao này Giao thay
 * @property ?CarbonImmutable $warranty_ends_on Hạn bảo hành kế thừa, khi lần giao là Đổi hàng
 * @property-read DispatchLine $dispatchLine
 * @property-read Slot $slot
 * @property-read StockUnit $stockUnit
 * @property-read ?Delivery $corrects
 * @property-read ?Replacement $replacement
 */
class Delivery extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispatch_line_id' => 'integer',
            'slot_id' => 'integer',
            'stock_unit_id' => 'integer',
            'warranty_days' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'delivered_by' => 'integer',
            'corrects_delivery_id' => 'integer',
            'warranty_ends_on' => 'immutable_date',
        ];
    }

    /**
     * Hạn bảo hành: ngày giao cộng thời hạn bảo hành đã giữ lúc giao (Đổi hàng: Hạn bảo hành kế thừa
     * của lần giao gốc), không quá Hạn sử dụng.
     */
    public function warrantyEndsOn(): CarbonImmutable
    {
        $end = $this->warranty_ends_on ?? $this->delivered_at->startOfDay()->addDays($this->warranty_days);
        $expiresOn = $this->stockUnit->expires_on;

        return $expiresOn !== null && $expiresOn->lt($end) ? $expiresOn : $end;
    }

    /**
     * Đổi hàng đã giao ra lần giao này.
     *
     * @return HasOne<Replacement, $this>
     */
    public function replacement(): HasOne
    {
        return $this->hasOne(Replacement::class);
    }

    /**
     * Đơn vị hàng và Slot đã giao, dạng chữ: "#12 · Slot #34".
     */
    public function unitLabel(): string
    {
        return "#{$this->stock_unit_id} · Slot #{$this->slot_id}";
    }

    /**
     * @return BelongsTo<DispatchLine, $this>
     */
    public function dispatchLine(): BelongsTo
    {
        return $this->belongsTo(DispatchLine::class);
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return BelongsTo<StockUnit, $this>
     */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    /**
     * Báo lỗi gần nhất của lần giao.
     *
     * @return HasOne<DefectReport, $this>
     */
    public function latestDefectReport(): HasOne
    {
        return $this->hasOne(DefectReport::class)->latestOfMany();
    }

    /**
     * Lần giao bị huỷ mà lần giao này Giao thay.
     *
     * @return BelongsTo<Delivery, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'corrects_delivery_id');
    }
}
