<?php

namespace App\Models;

use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kênh bán: nguồn phát sinh đơn cần giao. Chỉ tạo và sửa qua {@see SalesChannelDirectory}.
 *
 * @property int $id
 * @property string $name
 * @property SalesChannelType $type
 * @property bool $requires_external_ref
 * @property ?CarbonImmutable $hidden_at
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
     * Kênh bán còn dùng được để tạo Phiếu xuất.
     *
     * @param  Builder<SalesChannel>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }
}
