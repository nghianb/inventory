<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dòng nhập: phần của một Lô nhập dành cho đúng một Sản phẩm.
 *
 * @property int $id
 * @property int $batch_id
 * @property int $product_id
 * @property int $unit_cost
 * @property string $separator
 * @property ?string $pending_ciphertext
 * @property ?int $pending_key_version
 * @property ?array{rejected: list<array{line: int, class: string, reason: string}>, sample: list<array<string, string>>} $preview
 * @property int $valid_count
 * @property int $invalid_count
 * @property int $file_duplicate_count
 * @property int $stock_duplicate_count
 * @property-read Batch $batch
 * @property-read Product $product
 */
class BatchLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'pending_key_version' => 'integer',
            'preview' => 'array',
            'valid_count' => 'integer',
            'invalid_count' => 'integer',
            'file_duplicate_count' => 'integer',
            'stock_duplicate_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
