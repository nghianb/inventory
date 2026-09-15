<?php

namespace App\Models;

use App\Inventory\Catalog\ProductType;
use App\Inventory\Stock\MaskedContent;
use App\Inventory\Stock\StockUnitStatus;
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
 * @property string $dedupe_hash
 * @property ?array<string, string> $content
 * @property ?string $secret_ciphertext
 * @property ?int $secret_key_version
 * @property-read Product $product
 * @property-read BatchLine $batchLine
 * @property-read Collection<int, Slot> $slots
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
            'content' => 'array',
            'secret_key_version' => 'integer',
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
     * @return HasMany<Slot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class)->orderBy('id');
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
