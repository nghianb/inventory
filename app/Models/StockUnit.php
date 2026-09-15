<?php

namespace App\Models;

use App\Inventory\Catalog\ProductType;
use App\Inventory\Stock\MaskedContent;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\VoidReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Đơn vị hàng: một Mã dùng một lần hoặc một Tài khoản nằm trong kho. Nội dung nhạy cảm
 * chỉ có dạng mã hoá; model không bao giờ giải mã.
 *
 * @property int $id
 * @property int $batch_line_id
 * @property int $product_id
 * @property ProductType $kind
 * @property StockUnitStatus $status
 * @property int $unit_cost
 * @property int $slot_count
 * @property ?CarbonImmutable $expires_on
 * @property ?int $renews_stock_unit_id
 * @property bool $holds_dedupe_key
 * @property string $dedupe_hash
 * @property ?array<string, string> $content
 * @property ?string $secret_ciphertext
 * @property ?int $secret_key_version
 * @property ?VoidReason $void_reason lý do Huỷ hàng, khi Đơn vị hàng Đã huỷ
 * @property ?CarbonImmutable $voided_at
 * @property ?CarbonImmutable $defective_at lúc chuyển Lỗi, khi Đơn vị hàng Lỗi
 * @property-read Product $product
 * @property-read BatchLine $batchLine
 * @property-read Collection<int, Slot> $slots
 * @property-read ?StockUnit $renews
 */
class StockUnit extends Model
{
    protected $hidden = ['dedupe_hash', 'secret_ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProductType::class,
            'status' => StockUnitStatus::class,
            'unit_cost' => 'integer',
            'slot_count' => 'integer',
            'expires_on' => 'immutable_date',
            'holds_dedupe_key' => 'boolean',
            'content' => 'array',
            'secret_key_version' => 'integer',
            'void_reason' => VoidReason::class,
            'voided_at' => 'immutable_datetime',
            'defective_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<BatchLine, $this>
     */
    public function batchLine(): BelongsTo
    {
        return $this->belongsTo(BatchLine::class);
    }

    /**
     * Đơn vị hàng cũ mà Tài khoản này nhập lại (gia hạn).
     *
     * @return BelongsTo<StockUnit, $this>
     */
    public function renews(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class, 'renews_stock_unit_id');
    }

    /**
     * @return HasMany<Slot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class)->orderBy('id');
    }

    /**
     * Các lần nội dung của Đơn vị hàng bị xem, mới nhất trước.
     *
     * @return HasMany<RevealLogEntry, $this>
     */
    public function revealLogEntries(): HasMany
    {
        return $this->hasMany(RevealLogEntry::class)->latest('id');
    }

    /**
     * Nội dung theo tên hiển thị Trường nội dung: trường nhạy cảm che hoàn toàn.
     *
     * @return array<string, string>
     */
    public function maskedContent(): array
    {
        return MaskedContent::of($this->product->contentFields, $this->content ?? []);
    }
}
