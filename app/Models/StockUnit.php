<?php

namespace App\Models;

use App\Inventory\Catalog\StockForm;
use App\Inventory\Reports\AdjustmentEvents;
use App\Inventory\Stock\MaskedContent;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\VoidReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property StockForm $kind
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
            'kind' => StockForm::class,
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
     * Bán được: còn ít nhất một Slot thuộc Tồn bán được, tức giao ngay được hôm nay. Bám đúng
     * {@see SellableStock::slots()} để kho chỉ có một nghĩa "bán được", kể cả việc loại Sản phẩm
     * Ngừng bán.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopeSellable(Builder $query): void
    {
        $query->whereIn('stock_units.id', SellableStock::slots(CarbonImmutable::today())->select('slots.stock_unit_id'));
    }

    /**
     * Tạm ngừng vì Báo lỗi: có Báo lỗi Chờ xác minh và còn Slot Còn hàng, tức đang có hàng bị khoá
     * khỏi Tồn bán được chờ xác minh. Đơn vị đã giao sạch Slot không vào đây: không khoá lại gì.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopePausedByDefect(Builder $query): void
    {
        self::hasSlotInStock($query)->whereIn('stock_units.id', SellableStock::pausedUnits());
    }

    /**
     * Hàng Lỗi còn trong kho: Đơn vị hàng Lỗi còn Slot Còn hàng, tức còn Tồn lỗi nằm đó. Đơn vị Lỗi
     * đã giao hết Slot không vào đây; muốn xem cả chúng thì lọc theo Trạng thái.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopeDefectiveStock(Builder $query): void
    {
        self::hasSlotInStock($query)->where('status', StockUnitStatus::Defective);
    }

    /**
     * Quá hạn: Đơn vị hàng Hoạt động đã quá Hạn sử dụng mà Slot vẫn còn trong kho, tức Tổn thất hết
     * hạn đã phát sinh. Chép lại vị từ của {@see AdjustmentEvents::expiryLosses()} vì hai bên khác
     * hình dạng hẳn; đổi nghĩa "hết hạn" thì phải sửa cả hai nơi.
     *
     * @param  Builder<StockUnit>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        self::hasSlotInStock($query)
            ->where('status', StockUnitStatus::Active)
            ->where('expires_on', '<', CarbonImmutable::today()->toDateString());
    }

    /**
     * Còn ít nhất một Slot Còn hàng: điều kiện chung của các vị từ đếm việc cần làm, vì việc chỉ phát
     * sinh với hàng còn nằm trong kho.
     *
     * @param  Builder<StockUnit>  $query
     * @return Builder<StockUnit>
     */
    private static function hasSlotInStock(Builder $query): Builder
    {
        return $query->whereHas('slots', fn (Builder $slots) => $slots->where('status', SlotStatus::InStock));
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
