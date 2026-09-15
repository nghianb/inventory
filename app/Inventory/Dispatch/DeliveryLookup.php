<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use SensitiveParameter;

/**
 * Tra lần Giao hàng theo Khoá chống trùng khách gửi tới: chuỗi dán vào được chuẩn hoá theo cấu hình
 * của từng Sản phẩm rồi so HMAC khớp chính xác với Đơn vị hàng đã giao. Chỉ trả bản ghi, không giải
 * mã nên không ghi Nhật ký xem mã.
 */
class DeliveryLookup
{
    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private ContentCrypto $crypto,
    ) {}

    /**
     * @return Collection<int, Delivery> theo thứ tự giao
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     */
    public function byDedupeKey(User $actor, #[SensitiveParameter] string $value): Collection
    {
        $this->roles->authorize($actor, Role::BanHang);
        // Sai khoá HMAC thì mọi hash lệch và tra cứu âm thầm không thấy gì: báo lỗi thay vì trả rỗng.
        $this->fingerprints->verify();

        // Hash theo chuẩn hoá của từng Sản phẩm; các Sản phẩm cùng cách chuẩn hoá ra cùng hash.
        $productIdsByHash = Product::query()
            ->get(['id', 'case_insensitive', 'strip_separators'])
            ->reject(fn (Product $product): bool => $product->normalization()->apply($value) === '')
            ->groupBy(fn (Product $product): string => $this->crypto->dedupeHash($value, $product->normalization()))
            ->map(fn (Collection $products): array => $products->modelKeys());

        if ($productIdsByHash->isEmpty()) {
            return new Collection;
        }

        return Delivery::query()
            ->whereHas('stockUnit', fn (Builder $units) => $units->where(function (Builder $units) use ($productIdsByHash): void {
                foreach ($productIdsByHash as $hash => $productIds) {
                    $units->orWhere(fn (Builder $units) => $units->whereIn('product_id', $productIds)->where('dedupe_hash', $hash));
                }
            }))
            ->with(['dispatchLine.dispatch.salesChannel', 'dispatchLine.product', 'slot', 'stockUnit'])
            ->orderBy('id')
            ->get();
    }
}
