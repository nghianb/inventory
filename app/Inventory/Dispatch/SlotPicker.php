<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockTransition;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Chọn Slot theo Thứ tự xuất và giao, dùng chung cho tạo Phiếu xuất, Giao thêm, Giao thay và Đổi
 * hàng. Người gọi chạy trong transaction và ghi Sổ biến động kho.
 */
final class SlotPicker
{
    /**
     * Slot đang bị giao dịch khác khoá thì bỏ qua; Đơn vị hàng bị khoá chia sẻ để không đổi trạng
     * thái trước khi giao xong.
     */
    private const LOCK = 'FOR UPDATE OF slots SKIP LOCKED FOR SHARE OF stock_units SKIP LOCKED';

    /**
     * Khoá chia sẻ Sản phẩm theo thứ tự id (Quản trị không Ngừng bán hay đổi Mã sản phẩm, thời hạn
     * bảo hành giữa lúc kiểm tra và lúc giao; hai phiếu cùng Sản phẩm vẫn chạy song song), rồi chọn
     * và khoá Slot cho mọi dòng. Thiếu hàng ở bất kỳ dòng nào thì không giao gì.
     *
     * @param  list<DispatchLineDraft>  $lines  đã qua kiểm tra
     * @param  list<int>  $exceptUnitIds  Đơn vị hàng không được chọn (Giao thay: Đơn vị hàng vừa giao nhầm)
     * @param  bool  $allowDiscontinued  cho giao Sản phẩm Ngừng bán, khi giao bù cho lần giao cũ của chính Sản phẩm đó
     * @return array{EloquentCollection<int, Product>, array<int, Collection<int, stdClass>>}
     *
     * @throws InvalidDispatch
     * @throws OutOfStock
     */
    public static function lockAndPick(array $lines, array $exceptUnitIds = [], bool $allowDiscontinued = false): array
    {
        $products = self::lockProducts(array_map(fn (DispatchLineDraft $line): int => (int) $line->product?->getKey(), $lines), $allowDiscontinued);
        $today = CarbonImmutable::today();
        $picks = [];
        $shortages = [];

        foreach ($lines as $index => $line) {
            $product = $products[(int) $line->product?->getKey()];
            $picks[$index] = self::pick($product, $line->quantity, $today, $exceptUnitIds, $allowDiscontinued);

            if ($picks[$index]->count() < $line->quantity) {
                $shortages[] = new Shortage($product->id, $product->name, $line->quantity, $picks[$index]->count());
            }
        }

        if ($shortages !== []) {
            throw new OutOfStock($shortages);
        }

        return [$products, $picks];
    }

    /**
     * Đổi hàng: khoá chia sẻ Sản phẩm như {@see lockAndPick()}, rồi chọn và khoá Slot đầu tiên của
     * {@see replacementCandidates()}.
     *
     * @param  list<int>  $exceptUnitIds
     * @return stdClass hàng `id`, `stock_unit_id`, `cost`, `expires_on`, `covers` của Slot đã khoá
     *
     * @throws InvalidDispatch
     * @throws OutOfStock
     */
    public static function lockAndPickReplacement(Product $product, CarbonImmutable $coverUntil, array $exceptUnitIds, bool $allowDiscontinued): stdClass
    {
        $locked = self::lockProducts([(int) $product->getKey()], $allowDiscontinued)->firstOrFail();

        return self::replacementCandidates($locked, CarbonImmutable::today(), $coverUntil, $exceptUnitIds)->lock(self::LOCK)->first()
            ?? throw new OutOfStock([new Shortage($locked->id, $locked->name, 1, 0)]);
    }

    /**
     * Slot ứng viên Đổi hàng của một Sản phẩm, chưa khoá: Slot Còn hàng chưa quá Hạn sử dụng, kể cả
     * Sản phẩm Ngừng bán. Hạn còn lại tối thiểu được thay bằng điều kiện Hạn sử dụng phủ
     * `$coverUntil`: Slot phủ đủ (hoặc không có hạn) xếp trước theo Thứ tự xuất; sau đó là Slot hạn
     * ngắn hơn, hạn dài nhất trước vì phủ được nhiều Hạn bảo hành nhất.
     *
     * @param  list<int>  $exceptUnitIds
     */
    public static function replacementCandidates(Product $product, CarbonImmutable $today, CarbonImmutable $coverUntil, array $exceptUnitIds): Builder
    {
        $covers = '(stock_units.expires_on IS NULL OR stock_units.expires_on >= CAST(? AS date))';
        $cover = [$coverUntil->toDateString()];

        return SellableStock::unexpiredIncludingDiscontinued($today)
            ->where('stock_units.product_id', $product->id)
            ->when($exceptUnitIds !== [], fn (Builder $query) => $query->whereNotIn('stock_units.id', $exceptUnitIds))
            ->select('slots.id', 'slots.stock_unit_id', 'slots.cost', 'stock_units.expires_on')
            ->selectRaw("{$covers} AS covers", $cover)
            ->orderByRaw("{$covers} DESC", $cover)
            ->orderByRaw(
                "CASE WHEN {$covers} THEN EXISTS (SELECT 1 FROM slots delivered WHERE delivered.stock_unit_id = stock_units.id AND delivered.status = ?) END DESC",
                [...$cover, SlotStatus::Delivered->value],
            )
            ->orderByRaw("CASE WHEN {$covers} THEN stock_units.expires_on END ASC NULLS LAST", $cover)
            ->orderByRaw('stock_units.expires_on DESC NULLS LAST')
            ->orderBy('stock_units.id')
            ->orderBy('slots.id');
    }

    /**
     * Giao các Slot đã khoá theo một Dòng xuất: Slot Còn hàng → Đã giao, ghi Giao hàng (giữ thời hạn
     * bảo hành của Sản phẩm lúc giao).
     *
     * @param  Collection<int, stdClass>  $slots  các hàng `id`, `stock_unit_id` từ {@see lockAndPick()}
     * @param  array<string, mixed>  $attributes  cột thêm của lần giao: lần giao bị huỷ mà Slot Giao thay, Hạn bảo hành kế thừa của Đổi hàng
     * @return list<StockTransition> để người gọi ghi Sổ biến động kho
     */
    public static function deliver(int $dispatchLineId, Product $product, Collection $slots, User $actor, CarbonInterface $now, array $attributes = []): array
    {
        DB::table('slots')
            ->whereIn('id', $slots->pluck('id'))
            ->update(['status' => SlotStatus::Delivered->value, 'updated_at' => $now]);

        DB::table('deliveries')->insert($slots->map(fn (object $slot): array => [
            'dispatch_line_id' => $dispatchLineId,
            'slot_id' => $slot->id,
            'stock_unit_id' => $slot->stock_unit_id,
            'warranty_days' => $product->warranty_days,
            'delivered_at' => $now,
            'delivered_by' => $actor->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
            ...$attributes,
        ])->all());

        return $slots->map(fn (object $slot): StockTransition => new StockTransition((int) $slot->stock_unit_id, (int) $slot->id, SlotStatus::InStock, SlotStatus::Delivered))->values()->all();
    }

    /**
     * @param  list<int>  $productIds
     * @return EloquentCollection<int, Product> theo id
     *
     * @throws InvalidDispatch
     */
    private static function lockProducts(array $productIds, bool $allowDiscontinued): EloquentCollection
    {
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->sharedLock()
            ->get()
            ->keyBy('id');

        $discontinued = $products->filter(fn (Product $product): bool => $product->isDiscontinued());

        if (! $allowDiscontinued && $discontinued->isNotEmpty()) {
            throw new InvalidDispatch($discontinued->map(fn (Product $product): DispatchProblem => DispatchProblem::discontinued($product))->values()->all());
        }

        return $products;
    }

    /**
     * Chọn và khoá Slot theo Thứ tự xuất: Tài khoản đã giao dở trước, rồi Hạn sử dụng gần nhất
     * (không có hạn xếp sau), rồi hàng nhập trước.
     *
     * @param  list<int>  $exceptUnitIds
     * @return Collection<int, stdClass> các hàng `id`, `stock_unit_id` của Slot đã khoá
     */
    private static function pick(Product $product, int $quantity, CarbonImmutable $today, array $exceptUnitIds, bool $allowDiscontinued): Collection
    {
        return ($allowDiscontinued ? SellableStock::slotsIncludingDiscontinued($today) : SellableStock::slots($today))
            ->where('stock_units.product_id', $product->id)
            ->when($exceptUnitIds !== [], fn (Builder $query) => $query->whereNotIn('stock_units.id', $exceptUnitIds))
            ->select('slots.id', 'slots.stock_unit_id')
            ->orderByRaw(
                'EXISTS (SELECT 1 FROM slots delivered WHERE delivered.stock_unit_id = stock_units.id AND delivered.status = ?) DESC',
                [SlotStatus::Delivered->value],
            )
            ->orderByRaw('stock_units.expires_on ASC NULLS LAST')
            ->orderBy('stock_units.id')
            ->orderBy('slots.id')
            ->limit($quantity)
            ->lock(self::LOCK)
            ->get();
    }
}
