<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property-read DispatchLine $dispatchLine
 * @property-read Slot $slot
 * @property-read StockUnit $stockUnit
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
        ];
    }

    /**
     * Hạn bảo hành: ngày giao cộng thời hạn bảo hành đã giữ lúc giao, không quá Hạn sử dụng.
     */
    public function warrantyEndsOn(): CarbonImmutable
    {
        $end = $this->delivered_at->startOfDay()->addDays($this->warranty_days);
        $expiresOn = $this->stockUnit->expires_on;

        return $expiresOn !== null && $expiresOn->lt($end) ? $expiresOn : $end;
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
}
