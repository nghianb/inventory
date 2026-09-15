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
#[Fillable(['event', 'user_id', 'actor_id', 'email', 'details', 'ip_address', 'user_agent', 'occurred_at'])]
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
            'details' => 'array',
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

    /**
     * Quản trị thực hiện thao tác; null khi sự kiện không do ai khác gây ra hoặc làm từ server.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
