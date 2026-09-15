<?php

namespace App\Models;

use App\Inventory\Catalog\ProductType;
use App\Inventory\Encryption\Normalization;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Sản phẩm: loại hàng shop bán, khai báo Trường nội dung của hàng thuộc nó.
 * Chỉ tạo và sửa qua ProductCatalog.
 *
 * @property int $id
 * @property ProductType $type
 * @property string $name
 * @property string $code
 * @property int $default_slots
 * @property int $warranty_days
 * @property int $min_remaining_days
 * @property ?int $low_stock_threshold
 * @property bool $case_insensitive
 * @property bool $strip_separators
 * @property ?string $delivery_template Mẫu giao hàng; null thì dùng mẫu mặc định
 * @property ?CarbonImmutable $stocked_at
 * @property ?CarbonImmutable $discontinued_at
 * @property-read Collection<int, ContentField> $contentFields
 */
class Product extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'default_slots' => 'integer',
            'warranty_days' => 'integer',
            'min_remaining_days' => 'integer',
            'low_stock_threshold' => 'integer',
            'case_insensitive' => 'boolean',
            'strip_separators' => 'boolean',
            'stocked_at' => 'immutable_datetime',
            'discontinued_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ContentField, $this>
     */
    public function contentFields(): HasMany
    {
        return $this->hasMany(ContentField::class)->orderBy('position');
    }

    /**
     * @return HasMany<StockUnit, $this>
     */
    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /**
     * Slot Còn hàng của Sản phẩm (chưa lọc Tồn bán được).
     *
     * @return HasManyThrough<Slot, StockUnit, $this>
     */
    public function inStockSlots(): HasManyThrough
    {
        return $this->hasManyThrough(Slot::class, StockUnit::class)->where('slots.status', SlotStatus::InStock);
    }

    /**
     * Tồn lỗi: Slot Còn hàng của Đơn vị hàng Lỗi, không thuộc Tồn bán được.
     *
     * @return HasManyThrough<Slot, StockUnit, $this>
     */
    public function defectiveStockSlots(): HasManyThrough
    {
        return $this->inStockSlots()->where('stock_units.status', StockUnitStatus::Defective);
    }

    public function dedupeKeyField(): ContentField
    {
        return $this->contentFields->sole(fn (ContentField $field): bool => $field->is_dedupe_key);
    }

    /**
     * Chuẩn hoá áp cho Khoá chống trùng của hàng thuộc Sản phẩm này.
     */
    public function normalization(): Normalization
    {
        return new Normalization($this->case_insensitive, $this->strip_separators);
    }

    /**
     * Sản phẩm đã có Đơn vị hàng: cấu hình quan trọng bị khoá, không xoá được.
     * Nhập hàng phải khoá hàng Sản phẩm (FOR UPDATE) khi đặt `stocked_at`, cùng khoá
     * ProductCatalog dùng khi sửa, để không nhập hàng theo cấu hình đang bị đổi.
     */
    public function hasStock(): bool
    {
        return $this->stocked_at !== null;
    }

    public function isDiscontinued(): bool
    {
        return $this->discontinued_at !== null;
    }

    /**
     * Sản phẩm chưa Ngừng bán: còn Giữ hàng và Giao hàng mới được.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->whereNull('discontinued_at');
    }

    /**
     * Sản phẩm đã có Phiếu xuất: Mã sản phẩm bị khoá vì Kênh bán loại API tham chiếu bằng mã này.
     */
    public function hasDispatch(): bool
    {
        return DispatchLine::query()->where('product_id', $this->getKey())->exists();
    }
}
