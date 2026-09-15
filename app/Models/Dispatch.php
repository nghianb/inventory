<?php

namespace App\Models;

use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\ManualDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Phiếu xuất: một đơn cần giao từ một Kênh bán. Chỉ tạo qua {@see ManualDispatch}.
 *
 * @property int $id
 * @property int $sales_channel_id
 * @property string $external_ref
 * @property ?string $customer
 * @property ?string $note
 * @property DispatchStatus $status
 * @property int $created_by
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $result_revealed_at
 * @property ?CarbonImmutable $created_at
 * @property-read SalesChannel $salesChannel
 * @property-read User $creator
 * @property-read Collection<int, DispatchLine> $lines
 * @property-read Collection<int, Delivery> $deliveries
 */
class Dispatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sales_channel_id' => 'integer',
            'status' => DispatchStatus::class,
            'created_by' => 'integer',
            'completed_at' => 'immutable_datetime',
            'result_revealed_at' => 'immutable_datetime',
        ];
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

    /**
     * @return HasMany<DispatchLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DispatchLine::class)->orderBy('id');
    }

    /**
     * @return HasManyThrough<Delivery, DispatchLine, $this>
     */
    public function deliveries(): HasManyThrough
    {
        return $this->hasManyThrough(Delivery::class, DispatchLine::class);
    }

    /**
     * Phiếu xuất có hàng đã giao mà một trường không nhạy cảm chứa chuỗi tìm (không phân biệt hoa
     * thường). Trường nhạy cảm chỉ có dạng mã hoá nên không bao giờ khớp.
     *
     * @param  Builder<Dispatch>  $query
     */
    public function scopeWhereDeliveredContent(Builder $query, string $term): void
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $query->whereHas('deliveries.stockUnit', fn (Builder $units) => $units->whereRaw(
            "EXISTS (SELECT 1 FROM jsonb_each_text(stock_units.content) AS field WHERE field.value ILIKE ? ESCAPE '\\')",
            [$pattern],
        ));
    }

    /**
     * Lịch sử sửa phiếu, cũ trước.
     *
     * @return HasMany<DispatchRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DispatchRevision::class)->orderBy('id');
    }

    /**
     * Tổng Giá bán các Dòng xuất đã có Giá bán; null khi chưa dòng nào có.
     */
    public function totalSalePrice(): ?int
    {
        $prices = $this->lines->pluck('sale_price')->filter(fn (?int $price): bool => $price !== null);

        return $prices->isEmpty() ? null : (int) $prices->sum();
    }
}
