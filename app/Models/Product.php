<?php

namespace App\Models;

use App\Inventory\Catalog\StockForm;
use App\Inventory\Encryption\Normalization;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Sản phẩm: thứ shop bán. Thuộc đúng một Loại sản phẩm, nơi khai Dạng hàng và Trường nội dung
 * của hàng thuộc nó; Sản phẩm tự khai thời hạn bảo hành, Hạn còn lại tối thiểu, Ngưỡng sắp hết,
 * số slot mặc định và Mẫu giao hàng ghi đè. Chỉ tạo và sửa qua ProductCatalog.
 *
 * @property int $id
 * @property int $product_type_id
 * @property string $name
 * @property string $code
 * @property int $default_slots
 * @property int $warranty_days
 * @property int $min_remaining_days
 * @property ?int $low_stock_threshold
 * @property ?string $delivery_template Mẫu giao hàng riêng; null thì dùng mẫu của Loại
 * @property ?CarbonImmutable $stocked_at
 * @property ?CarbonImmutable $discontinued_at
 * @property-read ProductType $productType
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
            'default_slots' => 'integer',
            'warranty_days' => 'integer',
            'min_remaining_days' => 'integer',
            'low_stock_threshold' => 'integer',
            'stocked_at' => 'immutable_datetime',
            'discontinued_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductType, $this>
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /**
     * Trường nội dung của Sản phẩm là trường của Loại nó thuộc về. Quan hệ bắc thẳng từ cột
     * `product_type_id` của Sản phẩm sang cùng cột ở Trường nội dung (không qua khoá chính),
     * nên `with('contentFields')` và `$product->contentFields` vẫn dùng được như khi trường còn
     * thuộc Sản phẩm — chỉ khác là mọi Sản phẩm cùng Loại đọc ra đúng cùng một bộ.
     *
     * @return HasMany<ContentField, $this>
     */
    public function contentFields(): HasMany
    {
        return $this->hasMany(ContentField::class, 'product_type_id', 'product_type_id')->orderBy('position');
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
     * Slot Đã giữ của Sản phẩm: đang giam cho một Phiếu xuất nên không thuộc Tồn bán được, nhưng
     * vẫn nằm trong kho. Tách khỏi Còn hàng để hàng đang giữ không trông như đã bốc hơi.
     *
     * @return HasManyThrough<Slot, StockUnit, $this>
     */
    public function heldSlots(): HasManyThrough
    {
        return $this->hasManyThrough(Slot::class, StockUnit::class)->where('slots.status', SlotStatus::Reserved);
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
     * Dạng hàng của Sản phẩm, do Loại sản phẩm khai.
     */
    public function form(): StockForm
    {
        return $this->productType->form;
    }

    /**
     * Chuẩn hoá áp cho Khoá chống trùng của hàng thuộc Sản phẩm này, do Loại sản phẩm khai.
     */
    public function normalization(): Normalization
    {
        return $this->productType->normalization();
    }

    /**
     * Mẫu giao hàng dùng khi ghép tin nhắn, tìm theo ba bậc: mẫu riêng của Sản phẩm, rồi mẫu của
     * Loại, rồi null (mẫu mặc định liệt kê các trường). Đọc lúc ghép chứ không chép lúc tạo, nên
     * Loại đổi mẫu thì Sản phẩm đã ghi đè không bị đụng tới.
     */
    public function deliveryTemplate(): ?string
    {
        return $this->delivery_template ?? $this->productType->delivery_template;
    }

    /**
     * Sản phẩm đã có Đơn vị hàng: không chuyển được sang Loại khác, không xoá được, và khoá mọi
     * thay đổi chạm dữ liệu đã lưu trên Loại của nó.
     * Nhập hàng phải khoá hàng Sản phẩm (FOR UPDATE) khi đặt `stocked_at`, cùng khoá mà
     * ProductCatalog và ProductTypeCatalog dùng khi sửa, để không nhập hàng theo khai báo
     * đang bị đổi.
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
