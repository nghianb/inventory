<?php

namespace App\Inventory\Reports;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Báo cáo Lãi/lỗ: mỗi Sản phẩm một dòng, kèm một dòng tổng và một dòng 'Chưa có Giá bán'.
 *
 * Hai tầng. **Lãi gộp** là Doanh thu trừ Giá vốn các Slot khách thực nhận, theo định nghĩa dùng chung
 * ở {@see SoldLines}. **Điều chỉnh** là phần mất mát không đi qua một lần bán nào, theo định nghĩa
 * dùng chung ở {@see AdjustmentEvents}: tính theo thời điểm phát sinh, gắn với Sản phẩm và Nhà cung
 * cấp của Đơn vị hàng. **Lãi ròng kho** = Lãi gộp − Điều chỉnh.
 *
 * Mọi con số luôn tính lại theo dữ liệu hiện tại, nên sửa Giá bán hay Khôi phục hàng Lỗi làm đổi cả
 * số của một kỳ đã qua.
 *
 * Chỉ Quản trị. Xem hay xuất báo cáo không ghi nhật ký.
 */
class ProfitReport
{
    /**
     * Cột số liệu → truy vấn con cung cấp nó.
     */
    private const COLUMN_SOURCE = [
        'revenue' => 'sales',
        'cogs' => 'sales',
        'replacement_cost' => 'adjustments',
        'defective_loss' => 'adjustments',
        'wrong_delivery_loss' => 'adjustments',
        'content_exposed_loss' => 'adjustments',
        'discontinued_lot_loss' => 'adjustments',
        'expiry_loss' => 'adjustments',
        'reimbursement' => 'adjustments',
    ];

    public function __construct(private RoleGate $roles) {}

    public function canView(User $user): bool
    {
        return $this->roles->allows($user);
    }

    /**
     * Các dòng Sản phẩm theo tên, rồi dòng tổng, rồi dòng 'Chưa có Giá bán' nếu kỳ có Dòng xuất chưa
     * ghi Giá bán.
     *
     * @return list<ProfitReportRow>
     *
     * @throws MissingRole
     */
    public function rows(User $actor, ProfitReportFilter $filter): array
    {
        $this->roles->authorize($actor);

        $products = array_map(
            fn (stdClass $record): ProfitReportRow => ProfitReportRow::fromRecord($record),
            self::aggregates($filter)->get()->all(),
        );

        return array_values(array_filter([
            ...$products,
            $products === [] ? null : ProfitReportRow::totalOf(array_values($products)),
            self::unpricedRow($filter),
        ]));
    }

    /**
     * Cột báo cáo theo bộ lọc đang áp: khoá → tiêu đề. Là chỗ duy nhất quyết định cột nào hiện; panel
     * hỏi vào đây chứ không tự suy lại.
     *
     * @return array<string, string>
     */
    public function columns(ProfitReportFilter $filter): array
    {
        $adjustments = $filter->showsAdjustments();
        $whenAdjusted = fn (string $label): ?string => $adjustments ? $label : null;

        return array_filter([
            'code' => 'Mã sản phẩm',
            'name' => 'Sản phẩm',
            'revenue' => 'Doanh thu',
            'cogs' => 'Giá vốn hàng bán',
            'gross_profit' => 'Lãi gộp',
            'margin' => '% biên',
            'replacement_cost' => $whenAdjusted('Chi phí đổi hàng'),
            'defective_loss' => $whenAdjusted('Tổn thất hàng Lỗi'),
            'wrong_delivery_loss' => $whenAdjusted('Tổn thất giao nhầm'),
            'content_exposed_loss' => $whenAdjusted('Tổn thất lộ nội dung'),
            'discontinued_lot_loss' => $whenAdjusted('Tổn thất ngừng kinh doanh lô'),
            'expiry_loss' => $whenAdjusted('Tổn thất hết hạn'),
            'reimbursement' => $whenAdjusted('Bồi hoàn tiền'),
            'adjustments' => $whenAdjusted('Điều chỉnh'),
            'net_profit' => $whenAdjusted('Lãi ròng kho'),
        ], fn (?string $label): bool => $label !== null);
    }

    /**
     * File báo cáo, gồm cả dòng tổng và dòng 'Chưa có Giá bán'. Không ghi nhật ký.
     *
     * @throws MissingRole
     */
    public function export(User $actor, ProfitReportFilter $filter, ReportFormat $format): ReportExport
    {
        $columns = $this->columns($filter);

        return ReportExport::of(
            'bao-cao-lai-lo-'.$filter->from->toDateString().'-'.$filter->to->toDateString(),
            $format,
            array_values($columns),
            array_map(
                fn (ProfitReportRow $row): array => array_map($row->cell(...), array_keys($columns)),
                $this->rows($actor, $filter),
            ),
        );
    }

    /**
     * Một dòng cho mỗi Sản phẩm có số liệu trong kỳ. Tập Sản phẩm là hợp của các nguồn, vì một Sản
     * phẩm có thể chỉ có Điều chỉnh mà không bán được gì trong kỳ, hoặc ngược lại.
     */
    private static function aggregates(ProfitReportFilter $filter): QueryBuilder
    {
        $sources = self::sources($filter);
        $identifiers = array_map(
            fn (QueryBuilder $source): QueryBuilder => DB::query()->fromSub($source, 'source')->select('source.product_id'),
            array_values($sources),
        );
        $productIds = array_shift($identifiers);

        foreach ($identifiers as $next) {
            $productIds->union($next);
        }

        $query = DB::query()
            ->fromSub($productIds, 'ids')
            ->join('products', 'products.id', '=', 'ids.product_id');

        foreach ($sources as $alias => $source) {
            $query->leftJoinSub($source, $alias, "{$alias}.product_id", '=', 'ids.product_id');
        }

        return $query
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->select('ids.product_id', 'products.code')
            ->selectRaw('products.name AS product_name')
            ->selectRaw(collect(self::COLUMN_SOURCE)
                ->map(fn (string $alias, string $column): string => isset($sources[$alias])
                    ? "COALESCE({$alias}.{$column}, 0) AS {$column}"
                    : "0 AS {$column}")
                ->implode(', '))
            ->when($filter->productIds !== [], fn (QueryBuilder $only) => $only->whereIn('ids.product_id', $filter->productIds));
    }

    /**
     * Truy vấn con theo alias, mỗi cái gom số liệu theo `product_id`. Bộ lọc nào không mô tả được một
     * nguồn thì nguồn đó không được nối, và cột của nó cũng không hiện.
     *
     * @return non-empty-array<string, QueryBuilder>
     */
    private static function sources(ProfitReportFilter $filter): array
    {
        $sources = ['sales' => self::sales($filter)];

        if ($filter->showsAdjustments()) {
            $sources['adjustments'] = self::adjustments($filter);
        }

        return $sources;
    }

    /**
     * Doanh thu và Giá vốn hàng bán theo Sản phẩm của Dòng xuất. Chỉ Dòng xuất đã ghi Giá bán: dòng
     * chưa ghi Giá bán đi vào dòng 'Chưa có Giá bán'.
     *
     * Khi lọc Nhà cung cấp, Giá bán của cả Dòng xuất được **chia đều theo Slot**: một Dòng xuất có thể
     * gồm Slot của nhiều Nhà cung cấp, mà Giá bán là tổng tiền của cả dòng chứ không phải đơn giá, nên
     * phần của mỗi Nhà cung cấp là phần Slot họ góp vào dòng đó.
     */
    private static function sales(ProfitReportFilter $filter): QueryBuilder
    {
        return SoldLines::priced($filter)
            ->groupBy('dispatch_lines.product_id')
            ->addSelect('dispatch_lines.product_id');
    }

    /**
     * Dòng 'Chưa có Giá bán': tổng số Slot và Giá vốn của các Dòng xuất đã giao trong kỳ mà chưa ghi
     * Giá bán. Rỗng thì không có dòng.
     */
    private static function unpricedRow(ProfitReportFilter $filter): ?ProfitReportRow
    {
        $unpriced = SoldLines::inPeriod($filter)
            ->whereNull('dispatch_lines.sale_price')
            ->selectRaw('COALESCE(SUM(line_deliveries.counted_slots), 0) AS slots')
            ->selectRaw('COALESCE(SUM(line_deliveries.cogs), 0) AS cost')
            ->first();

        return $unpriced === null || (int) $unpriced->slots === 0
            ? null
            : ProfitReportRow::unpriced((int) $unpriced->slots, (int) $unpriced->cost);
    }

    /**
     * Các khoản Điều chỉnh gom theo Sản phẩm. Lọc Nhà cung cấp áp thẳng ở đây: mỗi khoản đã mang sẵn
     * Nhà cung cấp của Đơn vị hàng sinh ra nó.
     */
    private static function adjustments(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::query()
            ->fromSub(AdjustmentEvents::inPeriod($filter), 'events')
            ->when($filter->supplierId !== null, fn (QueryBuilder $ofSupplier) => $ofSupplier->where('events.supplier_id', $filter->supplierId))
            ->groupBy('events.product_id')
            ->select('events.product_id')
            ->selectRaw(AdjustmentEvents::totals());
    }
}
