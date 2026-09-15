<?php

namespace App\Models;

use App\Inventory\Dispatch\DispatchEditor;
use App\Inventory\Dispatch\DispatchRevisionField;
use App\Inventory\Security\AppendOnlyViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng lịch sử sửa Phiếu xuất: ai, khi nào, trường nào, cũ → mới. Chỉ-ghi-thêm; ghi qua
 * {@see DispatchEditor}.
 *
 * @property int $id
 * @property int $dispatch_id
 * @property ?int $dispatch_line_id
 * @property DispatchRevisionField $field
 * @property ?string $old_value
 * @property ?string $new_value
 * @property int $actor_id
 * @property CarbonImmutable $occurred_at
 * @property-read ?DispatchLine $dispatchLine
 * @property-read User $actor
 */
class DispatchRevision extends Model
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
            'dispatch_id' => 'integer',
            'dispatch_line_id' => 'integer',
            'field' => DispatchRevisionField::class,
            'actor_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * Tên trường; Giá bán kèm Sản phẩm của Dòng xuất.
     */
    public function label(): string
    {
        return $this->dispatchLine === null
            ? $this->field->label()
            : "{$this->field->label()} · {$this->dispatchLine->product->name}";
    }

    /**
     * @return BelongsTo<DispatchLine, $this>
     */
    public function dispatchLine(): BelongsTo
    {
        return $this->belongsTo(DispatchLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
