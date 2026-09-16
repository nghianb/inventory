<?php

namespace App\Models;

use App\Inventory\Api\ApiKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Khoá API: bí mật mà một Kênh bán loại API dùng để gọi vào kho. Chỉ tạo, xoay và thu hồi qua
 * {@see ApiKeys}; model không bao giờ giữ giá trị khoá, chỉ hash của nó.
 *
 * @property int $id
 * @property int $sales_channel_id
 * @property ?string $label
 * @property string $key_hash
 * @property string $prefix vài ký tự đầu của bí mật, để nhận ra khoá trong danh sách
 * @property int $created_by
 * @property ?int $rotates_api_key_id khoá cũ mà khoá này thay thế khi xoay
 * @property ?CarbonImmutable $last_used_at
 * @property ?CarbonImmutable $revoked_at
 * @property ?int $revoked_by
 * @property ?CarbonImmutable $created_at
 * @property-read SalesChannel $salesChannel
 * @property-read User $creator
 */
class ApiKey extends Model
{
    protected $hidden = ['key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sales_channel_id' => 'integer',
            'created_by' => 'integer',
            'rotates_api_key_id' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'revoked_by' => 'integer',
        ];
    }

    /**
     * Khoá đã thu hồi: không gọi vào kho được nữa, nhưng bản ghi ở lại vì các nhật ký tham chiếu tới nó.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Khoá dạng chữ cho danh sách và nhật ký: tên gợi nhớ nếu có, kèm vài ký tự đầu.
     */
    public function describe(): string
    {
        return $this->label === null ? "Khoá API #{$this->id} ({$this->prefix}…)" : "{$this->label} ({$this->prefix}…)";
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<SalesChannel, $this>
     */
    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
