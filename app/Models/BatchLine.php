<?php

namespace App\Models;

use App\Inventory\Intake\IntakeSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dòng nhập: phần của một Lô nhập dành cho đúng một Sản phẩm. Nội dung chờ xác nhận không
 * nằm trong DB mà trên ổ local, mã hoá (PendingContentStore).
 *
 * @property int $id
 * @property int $batch_id
 * @property int $product_id
 * @property int $unit_cost
 * @property string $separator
 * @property IntakeSource $source
 * @property ?string $file_name
 * @property ?int $slots
 * @property ?CarbonImmutable $expires_on
 * @property ?int $expires_after_days
 * @property ?array{rejected: list<array{line: int, class: string, reason: string}>, sample: list<array<string, string>>, ignored_columns?: list<string>} $preview
 * @property int $valid_count
 * @property int $renewal_count
 * @property int $invalid_count
 * @property int $file_duplicate_count
 * @property int $stock_duplicate_count
 * @property int $total_cost
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
            'source' => IntakeSource::class,
            'slots' => 'integer',
            'expires_on' => 'immutable_date',
            'expires_after_days' => 'integer',
            'preview' => 'array',
            'valid_count' => 'integer',
            'renewal_count' => 'integer',
            'invalid_count' => 'integer',
            'file_duplicate_count' => 'integer',
            'stock_duplicate_count' => 'integer',
            'total_cost' => 'integer',
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
