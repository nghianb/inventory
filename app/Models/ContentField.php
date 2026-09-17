<?php

namespace App\Models;

use App\Inventory\Catalog\ContentFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trường nội dung do một Loại sản phẩm khai báo, dùng chung cho mọi Sản phẩm của Loại.
 *
 * @property int $id
 * @property int $product_type_id
 * @property string $key
 * @property string $label
 * @property ContentFieldType $type
 * @property ?string $pattern
 * @property bool $required
 * @property bool $sensitive
 * @property bool $is_dedupe_key
 * @property int $position
 * @property-read ProductType $productType
 */
class ContentField extends Model
{
    protected $table = 'product_content_fields';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContentFieldType::class,
            'required' => 'boolean',
            'sensitive' => 'boolean',
            'is_dedupe_key' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductType, $this>
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }
}
