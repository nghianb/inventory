<?php

namespace App\Models;

use App\Inventory\Stock\SlotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slot: phần của Đơn vị hàng bán cho một khách; Mã dùng một lần có đúng một Slot.
 *
 * @property int $id
 * @property int $stock_unit_id
 * @property SlotStatus $status
 * @property-read StockUnit $stockUnit
 */
class Slot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SlotStatus::class,
        ];
    }

    /**
     * @return BelongsTo<StockUnit, $this>
     */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
