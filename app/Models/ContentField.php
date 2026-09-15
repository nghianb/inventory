<?php

namespace App\Models;

use App\Inventory\Catalog\ContentFieldType;
use Illuminate\Database\Eloquent\Model;

/**
 * Trường nội dung do một Sản phẩm khai báo.
 *
 * @property int $id
 * @property int $product_id
 * @property string $key
 * @property string $label
 * @property ContentFieldType $type
 * @property ?string $pattern
 * @property bool $required
 * @property bool $sensitive
 * @property bool $is_dedupe_key
 * @property int $position
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
}
