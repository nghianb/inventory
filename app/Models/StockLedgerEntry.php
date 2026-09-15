<?php

namespace App\Models;

use App\Inventory\Security\AppendOnlyViolation;
use App\Inventory\Stock\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng Sổ biến động kho: một lần chuyển trạng thái của Đơn vị hàng (slot_id null)
 * hoặc của Slot. Chỉ-ghi-thêm; ghi qua {@see StockLedger}.
 *
 * @property int $id
 * @property int $stock_unit_id
 * @property ?int $slot_id
 * @property ?string $from_status
 * @property string $to_status
 * @property ?string $reason
 * @property ?int $actor_id
 * @property CarbonImmutable $occurred_at
 */
class StockLedgerEntry extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw AppendOnlyViolation::for(static::class));
        static::deleting(fn () => throw AppendOnlyViolation::for(static::class));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
