<?php

namespace App\Models;

use App\Inventory\Dispatch\DispatchLineKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dòng xuất: phần của Phiếu xuất dành cho đúng một Sản phẩm.
 *
 * @property int $id
 * @property int $dispatch_id
 * @property int $product_id
 * @property DispatchLineKind $kind
 * @property int $quantity
 * @property ?int $sale_price Giá bán: tổng tiền của cả dòng
 * @property-read Dispatch $dispatch
 * @property-read Product $product
 * @property-read Collection<int, Delivery> $deliveries
 */
class DispatchLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispatch_id' => 'integer',
            'product_id' => 'integer',
            'kind' => DispatchLineKind::class,
            'quantity' => 'integer',
            'sale_price' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dispatch, $this>
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class)->orderBy('id');
    }
}
