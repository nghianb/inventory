<?php

namespace App\Inventory\Stock;

use App\Inventory\Warranty\DefectReportStatus;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tồn bán được: Slot Còn hàng giao được ngay. Một định nghĩa dùng chung cho form xuất kho,
 * Thứ tự xuất và (sau này) API kiểm tra tồn, báo cáo, cảnh báo sắp hết.
 */
class SellableStock
{
    public function count(Product $product): int
    {
        return $this->counts([(int) $product->getKey()])[(int) $product->getKey()];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int> số Slot bán được theo id Sản phẩm, đúng thứ tự truyền vào
     */
    public function counts(array $productIds): array
    {
        $rows = $productIds === [] ? collect() : self::slots(CarbonImmutable::today())
            ->whereIn('stock_units.product_id', $productIds)
            ->groupBy('stock_units.product_id')
            ->selectRaw('stock_units.product_id, COUNT(*) AS sellable')
            ->pluck('sellable', 'product_id');

        $counts = [];

        foreach ($productIds as $id) {
            $counts[$id] = (int) ($rows[$id] ?? 0);
        }

        return $counts;
    }

    public function level(Product $product): StockLevel
    {
        return StockLevel::of($this->count($product), $product->low_stock_threshold);
    }

    /**
     * Slot thuộc Tồn bán được vào ngày nghiệp vụ `$today`: Slot Còn hàng của Đơn vị hàng Hoạt
     * động, không bị tạm ngừng vì Báo lỗi Chờ xác minh, thuộc Sản phẩm chưa Ngừng bán, chưa quá Hạn
     * sử dụng và còn ít nhất Hạn còn lại tối thiểu ngày. Truy vấn nối `slots`, `stock_units`,
     * `products`.
     */
    public static function slots(CarbonImmutable $today): Builder
    {
        return self::slotsIncludingDiscontinued($today)->whereNull('products.discontinued_at');
    }

    /**
     * Như {@see slots()} nhưng tính cả Sản phẩm Ngừng bán: Slot Giao thay được cho lần giao cũ của
     * chính Sản phẩm đó. Không phải Tồn bán được.
     */
    public static function slotsIncludingDiscontinued(CarbonImmutable $today): Builder
    {
        return self::inStockOfActiveUnits()
            ->where(fn (Builder $query) => $query
                ->whereNull('stock_units.expires_on')
                ->orWhereRaw('stock_units.expires_on >= CAST(? AS date) + products.min_remaining_days', [$today->toDateString()]));
    }

    /**
     * Như {@see slotsIncludingDiscontinued()} nhưng chỉ đòi chưa quá Hạn sử dụng, không đòi Hạn còn
     * lại tối thiểu: ứng viên Đổi hàng, nơi điều kiện đó được thay bằng Hạn sử dụng phủ Hạn bảo hành
     * kế thừa. Không phải Tồn bán được.
     */
    public static function unexpiredIncludingDiscontinued(CarbonImmutable $today): Builder
    {
        return self::inStockOfActiveUnits()
            ->where(fn (Builder $query) => $query
                ->whereNull('stock_units.expires_on')
                ->orWhere('stock_units.expires_on', '>=', $today->toDateString()));
    }

    /**
     * Slot Còn hàng của Đơn vị hàng Hoạt động, không bị tạm ngừng vì Báo lỗi Chờ xác minh.
     */
    private static function inStockOfActiveUnits(): Builder
    {
        return DB::table('slots')
            ->join('stock_units', 'stock_units.id', '=', 'slots.stock_unit_id')
            ->join('products', 'products.id', '=', 'stock_units.product_id')
            ->where('slots.status', SlotStatus::InStock->value)
            ->where('stock_units.status', StockUnitStatus::Active->value)
            ->whereNotExists(fn (Builder $reports) => $reports
                ->selectRaw('1')
                ->from('defect_reports')
                ->whereColumn('defect_reports.stock_unit_id', 'stock_units.id')
                ->where('defect_reports.status', DefectReportStatus::Pending->value));
    }
}
