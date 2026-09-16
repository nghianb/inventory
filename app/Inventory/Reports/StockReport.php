<?php

namespace App\Inventory\Reports;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Báo cáo Tồn kho hiện tại: mỗi Sản phẩm một dòng, đếm theo Slot, chia thành Tồn bán được (định nghĩa
 * của {@see SellableStock}), Đã giữ, Tạm ngừng (Báo lỗi Chờ xác minh), Không đạt Hạn còn lại tối
 * thiểu, Tồn lỗi và Ngừng bán còn lại (Slot đạt hạn của Sản phẩm Ngừng bán, chỉ còn dùng cho Đổi hàng
 * và Giao thay). Slot Còn hàng của Đơn vị hàng Hoạt động đã quá Hạn sử dụng là Tổn thất hết hạn, không
 * còn là tồn. Giá trị tồn không gồm Tồn lỗi vì Giá vốn đó đã là Tổn thất hàng Lỗi; Tồn lỗi có cột Giá
 * vốn riêng. Lọc theo Nhà cung cấp chỉ đổi số đếm; Sắp hết luôn xét Tồn bán được của cả Sản phẩm, vì
 * Ngưỡng sắp hết là của Sản phẩm.
 *
 * Cả ba Vai trò xem được; Bán hàng không thấy giá trị Giá vốn và không lọc theo Nhà cung cấp. Xem hay
 * xuất báo cáo không ghi nhật ký.
 */
class StockReport
{
    // Sắp hết: có Ngưỡng sắp hết, chưa Ngừng bán, Tồn bán được của cả Sản phẩm không vượt ngưỡng.
    private const LOW_STOCK = '(products.low_stock_threshold IS NOT NULL AND products.discontinued_at IS NULL AND COALESCE(product_stock.sellable_slots, 0) <= products.low_stock_threshold)';

    private const EXPIRING = 'COALESCE(stock.expiring_slots, 0) > 0';

    public function __construct(private RoleGate $roles) {}

    public function canView(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    /**
     * Thấy giá trị Giá vốn và Nhà cung cấp.
     */
    public function seesCost(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    /**
     * Sản phẩm kèm các cột báo cáo (khoá của {@see columns()} trừ `code`, `name`, `status`,
     * `low_stock_threshold`), chưa sắp xếp.
     *
     * @return Builder<Product>
     *
     * @throws MissingRole
     */
    public function query(User $actor, StockReportFilter $filter): Builder
    {
        $this->roles->authorize($actor, Role::NhapKho, Role::BanHang);

        if ($filter->supplierId !== null) {
            $this->roles->authorize($actor, Role::NhapKho);
        }

        $today = CarbonImmutable::today();

        return Product::query()
            ->leftJoinSub(self::aggregates($today, $filter), 'stock', 'stock.product_id', '=', 'products.id')
            ->leftJoinSub(SellableStock::slots($today)
                ->groupBy('stock_units.product_id')
                ->select('stock_units.product_id')
                ->selectRaw('COUNT(*) AS sellable_slots'), 'product_stock', 'product_stock.product_id', '=', 'products.id')
            ->select('products.*')
            ->selectRaw(collect(['sellable_slots', 'reserved_slots', 'paused_slots', 'below_min_slots', 'defective_slots', 'discontinued_slots', 'stock_unit_count', 'stock_value', 'defective_value', 'expiring_slots', 'expiring_cost'])
                ->map(fn (string $column): string => "COALESCE(stock.{$column}, 0) AS {$column}")
                ->implode(', '))
            ->selectRaw(self::LOW_STOCK.' AS low_stock')
            ->when($filter->productIds !== [], fn (Builder $query) => $query->whereIn('products.id', $filter->productIds))
            // Sản phẩm Nhà cung cấp từng giao, kể cả khi đã hết hàng của họ.
            ->when($filter->supplierId !== null, fn (Builder $query) => $query->whereExists(fn (QueryBuilder $lines) => $lines
                ->selectRaw('1')
                ->from('batch_lines')
                ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
                ->whereColumn('batch_lines.product_id', 'products.id')
                ->where('batches.supplier_id', $filter->supplierId)
                ->where('batches.status', BatchStatus::Confirmed->value)))
            ->when($filter->lowStockOnly, fn (Builder $query) => $query->whereRaw(self::LOW_STOCK))
            ->when($filter->expiringOnly, fn (Builder $query) => $query->whereRaw(self::EXPIRING))
            ->when($filter->alertsOnly, fn (Builder $query) => $query->where(fn (Builder $alerts) => $alerts
                ->whereRaw(self::LOW_STOCK)
                ->orWhereRaw(self::EXPIRING)));
    }

    /**
     * @return list<StockReportRow> theo tên Sản phẩm
     *
     * @throws MissingRole
     */
    public function rows(User $actor, StockReportFilter $filter): array
    {
        return array_values($this->query($actor, $filter)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->get()
            ->map(fn (Product $product): StockReportRow => StockReportRow::fromProduct($product))
            ->all());
    }

    /**
     * Cột báo cáo theo Vai trò người xem: khoá → tiêu đề.
     *
     * @return array<string, string>
     */
    public function columns(User $viewer, StockReportFilter $filter): array
    {
        $values = $this->seesCost($viewer);

        return array_filter([
            'code' => 'Mã sản phẩm',
            'name' => 'Sản phẩm',
            'status' => 'Trạng thái',
            'sellable_slots' => 'Tồn bán được',
            'low_stock_threshold' => 'Ngưỡng sắp hết',
            'low_stock' => 'Sắp hết',
            'reserved_slots' => 'Đã giữ',
            'paused_slots' => 'Tạm ngừng',
            'below_min_slots' => 'Không đạt Hạn còn lại tối thiểu',
            'defective_slots' => 'Tồn lỗi',
            'discontinued_slots' => 'Ngừng bán còn lại',
            'stock_unit_count' => 'Đơn vị hàng',
            'stock_value' => $values ? 'Giá trị tồn' : null,
            'defective_value' => $values ? 'Giá vốn Tồn lỗi' : null,
            'expiring_slots' => "Hết hạn trong {$filter->expiringWithinDays} ngày",
            'expiring_cost' => $values ? 'Giá vốn sắp mất' : null,
        ], fn (?string $label): bool => $label !== null);
    }

    /**
     * File báo cáo, cột theo Vai trò người tải. Không ghi nhật ký.
     *
     * @throws MissingRole
     */
    public function export(User $actor, StockReportFilter $filter, ReportFormat $format): ReportExport
    {
        $columns = array_keys($this->columns($actor, $filter));

        return ReportExport::of(
            'bao-cao-ton-kho-'.CarbonImmutable::today()->toDateString(),
            $format,
            array_values($this->columns($actor, $filter)),
            array_map(fn (StockReportRow $row): array => array_map($row->cell(...), $columns), $this->rows($actor, $filter)),
        );
    }

    /**
     * Số Slot và Giá vốn theo Sản phẩm, trên Slot Đã giữ và Slot Còn hàng của Đơn vị hàng Hoạt động
     * (chưa quá Hạn sử dụng) hoặc Lỗi. Giá trị tồn tách khỏi Giá vốn Tồn lỗi.
     */
    private static function aggregates(CarbonImmutable $today, StockReportFilter $filter): QueryBuilder
    {
        $inStock = "slots.status = '".SlotStatus::InStock->value."'";
        $active = "stock_units.status = '".StockUnitStatus::Active->value."'";
        // Tồn lỗi: Slot Còn hàng của Đơn vị hàng Lỗi.
        $defective = "{$inStock} AND stock_units.status = '".StockUnitStatus::Defective->value."'";
        $expiring = "{$inStock} AND {$active} AND stock_units.expires_on BETWEEN CAST(? AS date) AND CAST(? AS date)";
        $expiringBindings = [$today->toDateString(), $today->addDays($filter->expiringWithinDays)->toDateString()];

        return DB::table('slots')
            ->join('stock_units', 'stock_units.id', '=', 'slots.stock_unit_id')
            ->leftJoinSub(SellableStock::slots($today)->select('slots.id'), 'sellable', 'sellable.id', '=', 'slots.id')
            // Như Tồn bán được nhưng tính cả Sản phẩm Ngừng bán: phần còn lại của Slot chưa quá hạn,
            // không tạm ngừng là Slot không đạt Hạn còn lại tối thiểu.
            ->leftJoinSub(SellableStock::slotsIncludingDiscontinued($today)->select('slots.id'), 'shelf_life', 'shelf_life.id', '=', 'slots.id')
            ->leftJoinSub(SellableStock::pausedUnits()->distinct(), 'paused', 'paused.stock_unit_id', '=', 'stock_units.id')
            ->whereIn('stock_units.status', [StockUnitStatus::Active->value, StockUnitStatus::Defective->value])
            ->where(fn (QueryBuilder $slots) => $slots
                ->where('slots.status', SlotStatus::Reserved->value)
                ->orWhere(fn (QueryBuilder $inStockSlots) => $inStockSlots
                    ->where('slots.status', SlotStatus::InStock->value)
                    ->where(fn (QueryBuilder $unexpired) => $unexpired
                        ->where('stock_units.status', StockUnitStatus::Defective->value)
                        ->orWhereNull('stock_units.expires_on')
                        ->orWhere('stock_units.expires_on', '>=', $today->toDateString()))))
            ->when($filter->supplierId !== null, fn (QueryBuilder $slots) => $slots
                ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id')
                ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
                ->where('batches.supplier_id', $filter->supplierId))
            ->groupBy('stock_units.product_id')
            ->select('stock_units.product_id')
            ->selectRaw('COUNT(sellable.id) AS sellable_slots')
            ->selectRaw("COUNT(*) FILTER (WHERE slots.status = '".SlotStatus::Reserved->value."') AS reserved_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE {$inStock} AND {$active} AND paused.stock_unit_id IS NOT NULL) AS paused_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE {$inStock} AND {$active} AND paused.stock_unit_id IS NULL AND shelf_life.id IS NULL) AS below_min_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE {$defective}) AS defective_slots")
            // Slot đạt Hạn còn lại tối thiểu, không tạm ngừng, nhưng Sản phẩm Ngừng bán: chỉ còn dùng
            // cho Đổi hàng và Giao thay của lần giao cũ.
            ->selectRaw("COUNT(*) FILTER (WHERE {$inStock} AND {$active} AND paused.stock_unit_id IS NULL AND shelf_life.id IS NOT NULL AND sellable.id IS NULL) AS discontinued_slots")
            ->selectRaw('COUNT(DISTINCT stock_units.id) AS stock_unit_count')
            // Giá trị tồn không gồm Tồn lỗi: Giá vốn đó đã là Tổn thất hàng Lỗi.
            ->selectRaw("COALESCE(SUM(slots.cost) FILTER (WHERE NOT ({$defective})), 0) AS stock_value")
            ->selectRaw("COALESCE(SUM(slots.cost) FILTER (WHERE {$defective}), 0) AS defective_value")
            ->selectRaw("COUNT(*) FILTER (WHERE {$expiring}) AS expiring_slots", $expiringBindings)
            ->selectRaw("COALESCE(SUM(slots.cost) FILTER (WHERE {$expiring}), 0) AS expiring_cost", $expiringBindings);
    }
}
