<?php

namespace App\Models;

use App\Inventory\Catalog\ProductTypeCatalog;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Encryption\Normalization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loại sản phẩm: khuôn dùng chung cho nhiều Sản phẩm mô tả hàng giống nhau. Khai Dạng hàng,
 * Trường nội dung, cách chuẩn hoá Khoá chống trùng và Mẫu giao hàng mặc định.
 * Chỉ tạo và sửa qua {@see ProductTypeCatalog}.
 *
 * @property int $id
 * @property string $name
 * @property StockForm $form Dạng hàng của mọi Đơn vị hàng thuộc Loại
 * @property bool $case_insensitive
 * @property bool $strip_separators
 * @property ?string $delivery_template Mẫu giao hàng của Loại; null thì dùng mẫu mặc định
 * @property ?CarbonImmutable $discontinued_at
 * @property-read Collection<int, ContentField> $contentFields
 * @property-read Collection<int, Product> $products
 */
class ProductType extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form' => StockForm::class,
            'case_insensitive' => 'boolean',
            'strip_separators' => 'boolean',
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
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Sản phẩm đã có hàng của Loại. Chỉ cần một cái là mọi thay đổi chạm dữ liệu đã lưu bị
     * từ chối trọn gói, kể cả với Sản phẩm anh em chưa có hàng (ADR 0004).
     *
     * @return HasMany<Product, $this>
     */
    public function stockedProducts(): HasMany
    {
        return $this->products()->whereNotNull('stocked_at');
    }

    public function dedupeKeyField(): ContentField
    {
        return $this->contentFields->sole(fn (ContentField $field): bool => $field->is_dedupe_key);
    }

    /**
     * Chuẩn hoá áp cho Khoá chống trùng của hàng thuộc mọi Sản phẩm của Loại này.
     */
    public function normalization(): Normalization
    {
        return new Normalization($this->case_insensitive, $this->strip_separators);
    }

    public function isDiscontinued(): bool
    {
        return $this->discontinued_at !== null;
    }

    /**
     * Loại chưa Ngừng dùng: còn chọn được khi tạo Sản phẩm mới.
     *
     * @param  Builder<ProductType>  $query
     */
    public function scopeNotDiscontinued(Builder $query): void
    {
        $query->whereNull('discontinued_at');
    }
}
