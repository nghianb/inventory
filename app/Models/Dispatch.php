<?php

namespace App\Models;

use App\Inventory\Dispatch\ApiDispatch;
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
 * Phiếu xuất: một đơn cần giao từ một Kênh bán. Chỉ tạo qua {@see ManualDispatch} (nhân viên) hoặc
 * {@see ApiDispatch} (website gọi bằng Khoá API); đúng một trong hai tác nhân có giá trị.
 *
 * @property int $id
 * @property int $sales_channel_id
 * @property string $external_ref
 * @property ?string $customer
 * @property ?string $note
 * @property DispatchStatus $status
 * @property ?int $created_by nhân viên tạo phiếu; null khi phiếu đến từ API
 * @property ?int $created_by_api_key_id Khoá API tạo phiếu; null khi nhân viên tạo trong panel
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $hold_expires_at hạn Giữ hàng của phiếu Đang giữ; null khi phiếu giữ và giao một bước
 * @property ?CarbonImmutable $result_revealed_at
 * @property ?int $result_by nhân viên của lần xuất kho gần nhất (tạo phiếu hoặc Giao thêm), người duy nhất xem được màn kết quả; null khi phiếu đến từ API
 * @property ?int $result_from_line_id Dòng xuất đầu tiên của lần Giao thêm gần nhất; null khi màn kết quả là của lần tạo phiếu
 * @property ?CarbonImmutable $created_at
 * @property-read SalesChannel $salesChannel
 * @property-read ?User $creator
 * @property-read ?ApiKey $createdByApiKey
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
            'created_by_api_key_id' => 'integer',
            'completed_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'result_revealed_at' => 'immutable_datetime',
            'result_by' => 'integer',
            'result_from_line_id' => 'integer',
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
     * @return BelongsTo<ApiKey, $this>
     */
    public function createdByApiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'created_by_api_key_id');
    }

    /**
     * "Ai" tạo phiếu, dạng chữ: tên nhân viên, hoặc Khoá API của Kênh bán loại API.
     */
    public function creatorLabel(): string
    {
        return $this->created_by !== null
            ? (string) $this->creator?->name
            : (string) $this->createdByApiKey?->describe();
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
