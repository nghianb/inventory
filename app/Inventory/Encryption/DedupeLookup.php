<?php

namespace App\Inventory\Encryption;

use App\Models\Product;
use App\Models\StockUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use SensitiveParameter;

/**
 * Tra Đơn vị hàng theo Khoá chống trùng khách gửi tới. Chuỗi dán vào được chuẩn hoá theo Loại sản
 * phẩm của **từng** Sản phẩm rồi so HMAC khớp chính xác, nên một chuỗi sinh ra nhiều hash: các Sản
 * phẩm có Loại cùng cách chuẩn hoá chung một hash. Chỉ dựng truy vấn, không giải mã gì, nên không ghi Nhật ký
 * xem mã.
 */
final class DedupeLookup
{
    public function __construct(private ContentCrypto $crypto) {}

    /**
     * Truy vấn các Đơn vị hàng có Khoá chống trùng khớp `$value`; người gọi thêm điều kiện của mình
     * rồi `get()`.
     *
     * @return ?Builder<StockUnit> null khi không Sản phẩm nào chuẩn hoá chuỗi này ra khác rỗng: không
     *                             thể có kết quả nào, nên người gọi trả rỗng thay vì chạy một truy
     *                             vấn không thu hẹp gì và quét cả kho
     *
     * @throws InvalidKeyConfiguration
     * @throws DedupeHashesStale kho còn lẫn hash cũ và hash mới nên kết quả không đáng tin
     */
    public function matchingUnits(#[SensitiveParameter] string $value): ?Builder
    {
        if (KeyRotation::hasStaleDedupeHashes()) {
            throw new DedupeHashesStale;
        }

        $productIdsByHash = Product::query()
            ->with('productType')
            ->get(['id', 'product_type_id'])
            ->reject(fn (Product $product): bool => $product->normalization()->apply($value) === '')
            ->groupBy(fn (Product $product): string => $this->crypto->dedupeHash($value, $product->normalization()))
            ->map(fn (Collection $products): array => $products->modelKeys());

        if ($productIdsByHash->isEmpty()) {
            return null;
        }

        return StockUnit::query()->where(function (Builder $match) use ($productIdsByHash): void {
            foreach ($productIdsByHash as $hash => $productIds) {
                $match->orWhere(fn (Builder $pair) => $pair
                    ->whereIn('stock_units.product_id', $productIds)
                    ->where('stock_units.dedupe_hash', $hash));
            }
        });
    }
}
