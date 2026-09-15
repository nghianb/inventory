<?php

namespace App\Models;

use App\Inventory\Security\AppendOnlyViolation;
use App\Inventory\Security\SecurityEvent;
use App\Inventory\Security\SecurityLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng Nhật ký bảo mật. Chỉ-ghi-thêm: ứng dụng không bao giờ sửa hay xoá.
 * Ghi qua {@see SecurityLog}.
 */
#[Fillable(['event', 'user_id', 'email', 'ip_address', 'user_agent', 'occurred_at'])]
class SecurityLogEntry extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw AppendOnlyViolation::for(static::class));
        static::deleting(fn () => throw AppendOnlyViolation::for(static::class));
    }

    protected function casts(): array
    {
        return [
            'event' => SecurityEvent::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
