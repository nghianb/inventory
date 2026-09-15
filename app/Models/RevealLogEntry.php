<?php

namespace App\Models;

use App\Inventory\Reveal\RevealContextType;
use App\Inventory\Reveal\RevealLog;
use App\Inventory\Security\AppendOnlyViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng Nhật ký xem mã: ai (nhân viên hoặc Khoá API), khi nào, Slot nào, qua Ngữ cảnh xem
 * mã nào, lý do. Không chứa nội dung mã. Chỉ-ghi-thêm; ghi qua {@see RevealLog}.
 *
 * @property int $id
 * @property ?int $user_id
 * @property ?int $api_key_id
 * @property ?int $slot_id
 * @property ?int $stock_unit_id
 * @property RevealContextType $context
 * @property ?int $context_id
 * @property string $reason
 * @property CarbonImmutable $occurred_at
 * @property-read ?User $user
 * @property-read ?Slot $slot
 * @property-read ?StockUnit $stockUnit
 */
class RevealLogEntry extends Model
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
            'user_id' => 'integer',
            'api_key_id' => 'integer',
            'slot_id' => 'integer',
            'stock_unit_id' => 'integer',
            'context' => RevealContextType::class,
            'context_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * Tác nhân dạng chữ: tên nhân viên hoặc số Khoá API.
     */
    public function actorLabel(): string
    {
        return $this->user_id !== null ? (string) $this->user?->name : "Khoá API #{$this->api_key_id}";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
