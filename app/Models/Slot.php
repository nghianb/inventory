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
 * @property int $cost Giá vốn Slot: Giá vốn Đơn vị hàng chia đều cho số slot
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
            'cost' => 'integer',
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
