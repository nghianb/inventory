<?php

namespace App\Models;

use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kênh bán: nguồn phát sinh đơn cần giao. Chỉ tạo và sửa qua {@see SalesChannelDirectory}.
 *
 * @property int $id
 * @property string $name
 * @property SalesChannelType $type
 * @property bool $requires_external_ref
 * @property int $hold_minutes hạn Giữ hàng của kênh API; kênh thủ công giữ và giao một bước nên không dùng
 * @property bool $requires_sale_price
 * @property ?CarbonImmutable $hidden_at
 * @property-read Collection<int, ApiKey> $apiKeys
 */
class SalesChannel extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SalesChannelType::class,
            'requires_external_ref' => 'boolean',
            'hold_minutes' => 'integer',
            'requires_sale_price' => 'boolean',
            'hidden_at' => 'immutable_datetime',
        ];
    }

    /**
     * Kênh bán ngừng dùng: không tạo Phiếu xuất mới, Phiếu xuất cũ giữ nguyên.
     */
    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Kênh loại API: website gọi vào kho bằng Khoá API, không có form trong panel.
     */
    public function isApi(): bool
    {
        return $this->type === SalesChannelType::Api;
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class)->orderBy('id');
    }

    /**
     * Kênh bán còn dùng được để tạo Phiếu xuất.
     *
     * @param  Builder<SalesChannel>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }
}
