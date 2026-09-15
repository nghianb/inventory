<?php

namespace App\Models;

use App\Inventory\Catalog\ProductType;
use App\Inventory\Encryption\Normalization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
