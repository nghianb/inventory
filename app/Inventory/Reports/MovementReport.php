<?php

namespace App\Inventory\Reports;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Báo cáo Nhập/xuất: luồng hàng vào ra trong một khoảng ngày, mỗi Sản phẩm một dòng, đếm theo Slot.
 * Chỉ Sản phẩm có biến động trong khoảng mới có dòng.
 *
 * Nhập tính theo ngày xác nhận Lô nhập; hàng Huỷ nhập bị loại hẳn (coi như chưa từng vào kho) nên
 * con số của một kỳ cũ giảm đi sau khi Huỷ nhập. Xuất tính theo thời điểm Giao hàng, bỏ qua lần
 * giao đã bị Giao thay vì Slot đó đã Huỷ hàng và khách nhận Slot khác.
 *
 * Cả ba Vai trò xem được; Bán hàng không thấy Giá vốn, Giá bán và không lọc theo Nhà cung cấp. Xem
 * hay xuất báo cáo không ghi nhật ký.
 */
class MovementReport
{
    /**
     * Cột số liệu → truy vấn con cung cấp nó.
     */
    private const COLUMN_SOURCE = [
        'in_slots' => 'intake',
        'in_replacement_slots' => 'intake',
        'in_cost' => 'intake',
        'sold_slots' => 'outflow',
        'replacement_slots' => 'outflow',
        'corrective_slots' => 'outflow',
        'out_cost' => 'outflow',
        'sale_total' => 'sales',
        'voided_slots' => 'losses',
        'defective_slots' => 'losses',
    ];

    public function __construct(private RoleGate $roles) {}

    public function canView(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho, Role::BanHang);
    }

    /**
     * Thấy Giá vốn, Giá bán và lọc theo Nhà cung cấp.
     */
    public function seesValues(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    /**
     * Sản phẩm có biến động trong khoảng, kèm các cột số liệu, chưa sắp xếp.
     *
     * @return Builder<Product>
     *
     * @throws MissingRole
     */
    public function query(User $actor, MovementReportFilter $filter): Builder
    {
        $this->roles->authorize($actor, Role::NhapKho, Role::BanHang);

        if ($filter->supplierId !== null) {
            $this->roles->authorize($actor, Role::NhapKho);
        }

        $sources = self::sources($filter);
        $query = Product::query();

        foreach ($sources as $alias => $source) {
            $query->leftJoinSub($source, $alias, "{$alias}.product_id", '=', 'products.id');
        }

        return $query
            ->select('products.*')
            ->selectRaw(collect(self::COLUMN_SOURCE)
                ->map(fn (string $alias, string $column): string => isset($sources[$alias])
                    ? "COALESCE({$alias}.{$column}, 0) AS {$column}"
                    : "0 AS {$column}")
                ->implode(', '))
            // Báo cáo luồng hàng, không phải danh mục: Sản phẩm không biến động thì không có dòng.
            ->where(function (Builder $moved) use ($sources): void {
                foreach (array_keys($sources) as $alias) {
                    $moved->orWhereNotNull("{$alias}.product_id");
                }
            })
            ->when($filter->productIds !== [], fn (Builder $only) => $only->whereIn('products.id', $filter->productIds));
    }

    /**
     * @return list<MovementReportRow> theo tên Sản phẩm
     *
     * @throws MissingRole
     */
    public function rows(User $actor, MovementReportFilter $filter): array
    {
        return array_values($this->query($actor, $filter)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->get()
            ->map(fn (Product $product): MovementReportRow => MovementReportRow::fromProduct($product))
            ->all());
    }

    /**
     * Cột báo cáo theo Vai trò người xem và theo bộ lọc đang áp: khoá → tiêu đề. Là chỗ duy nhất
     * quyết định cột nào hiện; panel hỏi vào đây chứ không tự suy lại.
     *
     * @return array<string, string>
     */
    public function columns(User $viewer, MovementReportFilter $filter): array
    {
        $stockFlow = $filter->showsStockFlow();

        return array_filter([
            'code' => 'Mã sản phẩm',
            'name' => 'Sản phẩm',
            'in_slots' => $stockFlow ? 'Nhập' : null,
            'in_replacement_slots' => $stockFlow ? 'Trong đó hàng thay thế' : null,
            'in_cost' => $stockFlow && $this->seesValues($viewer) ? 'Giá vốn nhập' : null,
            'sold_slots' => 'Giao bán',
            'replacement_slots' => 'Đổi hàng',
            'corrective_slots' => 'Giao thay',
            'out_cost' => $this->seesValues($viewer) ? 'Giá vốn xuất' : null,
            'sale_total' => $this->seesValues($viewer) && $filter->showsSalePrice() ? 'Giá bán' : null,
            'voided_slots' => $stockFlow ? 'Huỷ hàng' : null,
            'defective_slots' => $stockFlow ? 'Chuyển Tồn lỗi' : null,
        ], fn (?string $label): bool => $label !== null);
    }

    /**
     * File báo cáo, cột theo Vai trò người tải và theo bộ lọc đang áp. Không ghi nhật ký.
     *
     * @throws MissingRole
     */
    public function export(User $actor, MovementReportFilter $filter, ReportFormat $format): ReportExport
    {
        $columns = $this->columns($actor, $filter);

        return ReportExport::of(
            'bao-cao-nhap-xuat-'.$filter->from->toDateString().'-'.$filter->to->toDateString(),
            $format,
            array_values($columns),
            array_map(
                fn (MovementReportRow $row): array => array_map($row->cell(...), array_keys($columns)),
                $this->rows($actor, $filter),
            ),
        );
    }

    /**
     * Truy vấn con theo alias, mỗi cái gom số liệu theo `product_id`. Bộ lọc nào không mô tả được
     * một nguồn thì nguồn đó không được nối, và cột của nó cũng không hiện.
     *
     * @return array<string, QueryBuilder>
     */
    private static function sources(MovementReportFilter $filter): array
    {
        $sources = ['outflow' => self::outflow($filter)];

        if ($filter->showsStockFlow()) {
            $sources['intake'] = self::intake($filter);
            $sources['losses'] = self::losses($filter);
        }

        if ($filter->showsSalePrice()) {
            $sources['sales'] = self::sales($filter);
        }

        return $sources;
    }

    /**
     * Slot vào kho theo ngày xác nhận Lô nhập. Hàng Huỷ nhập bị loại hẳn: coi như chưa từng vào kho.
     */
    private static function intake(MovementReportFilter $filter): QueryBuilder
    {
        // Luôn nối `batches`: cần ngày xác nhận và cờ hàng thay thế, không chỉ khi lọc Nhà cung cấp.
        return DB::table('slots')
            ->join('stock_units', 'stock_units.id', '=', 'slots.stock_unit_id')
            ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id')
            ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
            ->where('batches.status', BatchStatus::Confirmed->value)
            ->where('batches.confirmed_at', '>=', $filter->startsAt())
            ->where('batches.confirmed_at', '<', $filter->endsBefore())
            ->where('stock_units.status', '<>', StockUnitStatus::Reversed->value)
            ->when($filter->supplierId !== null, fn (QueryBuilder $ofSupplier) => $ofSupplier->where('batches.supplier_id', $filter->supplierId))
            ->groupBy('stock_units.product_id')
            ->select('stock_units.product_id')
            ->selectRaw('COUNT(*) AS in_slots')
            ->selectRaw('COUNT(*) FILTER (WHERE batches.supplier_claim_id IS NOT NULL) AS in_replacement_slots')
            ->selectRaw('COALESCE(SUM(slots.cost), 0) AS in_cost');
    }

    /**
     * Slot đã giao theo thời điểm Giao hàng, tách theo loại lần giao. Giao thay nhận ra ở lần giao
     * trỏ về lần giao bị huỷ: Giao thay cùng Sản phẩm vào thẳng Dòng xuất gốc nên loại Dòng xuất
     * không đủ để phân biệt.
     */
    private static function outflow(MovementReportFilter $filter): QueryBuilder
    {
        $corrective = "(deliveries.corrects_delivery_id IS NOT NULL OR dispatch_lines.kind = '".DispatchLineKind::Corrective->value."')";
        $sale = "dispatch_lines.kind IN ('".implode("', '", DispatchLineKind::salePriceValues())."')";
        $replacement = "dispatch_lines.kind = '".DispatchLineKind::Replacement->value."'";

        return DB::table('deliveries')
            ->join('dispatch_lines', 'dispatch_lines.id', '=', 'deliveries.dispatch_line_id')
            ->join('slots', 'slots.id', '=', 'deliveries.slot_id')
            ->join('stock_units', 'stock_units.id', '=', 'deliveries.stock_unit_id')
            ->where('deliveries.delivered_at', '>=', $filter->startsAt())
            ->where('deliveries.delivered_at', '<', $filter->endsBefore())
            // Lần giao đã bị Giao thay không còn là hàng ra: Slot đó đã Huỷ hàng và khách nhận Slot
            // khác, nên tính cả hai thì một đơn một Slot hoá thành hai. Cùng cách Lãi gộp bỏ qua
            // Slot giao nhầm đã Huỷ hàng; Slot vẫn hiện ở cột Huỷ hàng.
            ->whereNotExists(fn (QueryBuilder $corrected) => $corrected
                ->selectRaw('1')
                ->from('deliveries AS corrections')
                ->whereColumn('corrections.corrects_delivery_id', 'deliveries.id'))
            ->when($filter->supplierId !== null, fn (QueryBuilder $ofSupplier) => self::fromSupplier($ofSupplier, (int) $filter->supplierId))
            ->when($filter->salesChannelId !== null, fn (QueryBuilder $ofChannel) => $ofChannel
                ->join('dispatches', 'dispatches.id', '=', 'dispatch_lines.dispatch_id')
                ->where('dispatches.sales_channel_id', $filter->salesChannelId))
            ->groupBy('stock_units.product_id')
            ->select('stock_units.product_id')
            ->selectRaw("COUNT(*) FILTER (WHERE NOT {$corrective} AND {$sale}) AS sold_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE NOT {$corrective} AND {$replacement}) AS replacement_slots")
            ->selectRaw("COUNT(*) FILTER (WHERE {$corrective}) AS corrective_slots")
            ->selectRaw('COALESCE(SUM(slots.cost), 0) AS out_cost');
    }

    /**
     * Chỉ hàng của một Nhà cung cấp: Nhà cung cấp của Lô nhập mà Đơn vị hàng thuộc về.
     */
    private static function fromSupplier(QueryBuilder $query, int $supplierId): QueryBuilder
    {
        return $query
            ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id')
            ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
            ->where('batches.supplier_id', $supplierId);
    }

    /**
     * Tổng Giá bán các Dòng xuất có lần Giao hàng đầu tiên trong khoảng. Giá bán là tổng tiền của cả
     * Dòng xuất, không phải đơn giá, nên mỗi Dòng xuất chỉ tính một lần, vào kỳ nó bắt đầu được
     * giao. Chỉ Giao bán, Giao thêm và Ghi nhận giao bù: Đổi hàng và Giao thay không có Giá bán —
     * ràng buộc `dispatch_lines_sale_price_kind` giữ điều đó ở tầng DB, đây lọc theo loại cho đúng ý.
     */
    private static function sales(MovementReportFilter $filter): QueryBuilder
    {
        $firstDelivery = DB::table('deliveries')
            ->groupBy('deliveries.dispatch_line_id')
            ->select('deliveries.dispatch_line_id')
            ->selectRaw('MIN(deliveries.delivered_at) AS first_delivered_at');

        return DB::table('dispatch_lines')
            ->joinSub($firstDelivery, 'first_delivery', 'first_delivery.dispatch_line_id', '=', 'dispatch_lines.id')
            ->whereIn('dispatch_lines.kind', DispatchLineKind::salePriceValues())
            ->whereNotNull('dispatch_lines.sale_price')
            ->where('first_delivery.first_delivered_at', '>=', $filter->startsAt())
            ->where('first_delivery.first_delivered_at', '<', $filter->endsBefore())
            ->when($filter->salesChannelId !== null, fn (QueryBuilder $ofChannel) => $ofChannel
                ->join('dispatches', 'dispatches.id', '=', 'dispatch_lines.dispatch_id')
                ->where('dispatches.sales_channel_id', $filter->salesChannelId))
            ->groupBy('dispatch_lines.product_id')
            ->select('dispatch_lines.product_id')
            ->selectRaw('COALESCE(SUM(dispatch_lines.sale_price), 0) AS sale_total');
    }

    /**
     * Slot rời vòng đời bán trong khoảng mà không thu tiền: Huỷ hàng theo mốc Huỷ hàng, và Slot còn
     * trong kho chuyển thành Tồn lỗi theo mốc Tổn thất hàng Lỗi (Đánh dấu Lỗi hoặc Báo lỗi Xác nhận
     * cả Đơn vị hàng). Khôi phục xoá mốc đó nên hàng đã Khôi phục không còn tính. Slot đã giao của
     * Đơn vị hàng chuyển Lỗi không vào đây: chúng không phải Tồn lỗi.
     */
    private static function losses(MovementReportFilter $filter): QueryBuilder
    {
        $from = $filter->startsAt();
        $to = $filter->endsBefore();
        $within = fn (string $column): string => "{$column} >= ? AND {$column} < ?";
        $bindings = [$from, $to];

        return DB::table('slots')
            ->join('stock_units', 'stock_units.id', '=', 'slots.stock_unit_id')
            ->when($filter->supplierId !== null, fn (QueryBuilder $ofSupplier) => self::fromSupplier($ofSupplier, (int) $filter->supplierId))
            // Có tải trọng, không thừa so với FILTER bên dưới: thiếu nó thì mọi Sản phẩm từng có Slot
            // đều ra một dòng toàn số 0 và bị coi là "có biến động trong kỳ".
            ->where(fn (QueryBuilder $left) => $left
                ->where(fn (QueryBuilder $voided) => $voided->where('slots.voided_at', '>=', $from)->where('slots.voided_at', '<', $to))
                ->orWhere(fn (QueryBuilder $defective) => $defective->where('slots.defective_loss_at', '>=', $from)->where('slots.defective_loss_at', '<', $to)))
            ->groupBy('stock_units.product_id')
            ->select('stock_units.product_id')
            ->selectRaw('COUNT(*) FILTER (WHERE '.$within('slots.voided_at').') AS voided_slots', $bindings)
            ->selectRaw('COUNT(*) FILTER (WHERE '.$within('slots.defective_loss_at').') AS defective_slots', $bindings);
    }
}
