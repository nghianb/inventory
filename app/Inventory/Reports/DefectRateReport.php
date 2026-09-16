<?php

namespace App\Inventory\Reports;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Báo cáo Tỉ lệ lỗi theo Nhà cung cấp: mỗi Nhà cung cấp × Sản phẩm một dòng, kèm dòng tổng của từng
 * Nhà cung cấp, đếm theo Đơn vị hàng.
 *
 * Tính theo lứa nhập: tập xét là Đơn vị hàng có Lô nhập xác nhận trong khoảng, nên tử số và mẫu số
 * luôn trên cùng một tập và con số của một kỳ cũ còn tăng dần khi hàng đã bán lộ lỗi. Mẫu số là Đơn vị
 * hàng đã giao ít nhất một Slot; tử số là phần đang Lỗi trong đó, gồm Lỗi từ Báo lỗi Phạm vi cả Đơn vị
 * hàng và từ Đánh dấu Lỗi. Hàng đã Khôi phục không còn Lỗi nên rời tử số; Báo lỗi chỉ Slot không đổi
 * trạng thái Đơn vị hàng nên không vào tử số; Giao thay không đi qua Báo lỗi và Slot giao nhầm chỉ bị
 * Huỷ hàng, nên lần giao đã bị Giao thay cũng không tính là đã giao.
 *
 * Hai cột đứng ngoài Tỉ lệ lỗi: 'Dòng lỗi/trùng khi nhập' là chất lượng file Nhà cung cấp gửi (dòng bị
 * bỏ chưa từng thành hàng), 'Lỗi trong kho' là Đơn vị hàng Lỗi chưa giao Slot nào.
 *
 * Chỉ Quản trị và Nhập kho. Xem hay xuất báo cáo không ghi nhật ký.
 */
class DefectRateReport
{
    public function __construct(private RoleGate $roles) {}

    public function canView(User $user): bool
    {
        return $this->roles->allows($user, Role::NhapKho);
    }

    /**
     * Các dòng Nhà cung cấp × Sản phẩm theo tên Nhà cung cấp rồi tên Sản phẩm, mỗi Nhà cung cấp kết
     * thúc bằng một dòng tổng.
     *
     * @return list<DefectRateReportRow>
     *
     * @throws MissingRole
     */
    public function rows(User $actor, DefectRateReportFilter $filter): array
    {
        $this->roles->authorize($actor, Role::NhapKho);

        $rows = [];

        foreach (self::aggregates($filter)->get()->groupBy('supplier_id') as $supplier) {
            $products = $supplier->map(fn (stdClass $record): DefectRateReportRow => DefectRateReportRow::fromRecord($record))->all();
            $rows = [...$rows, ...$products, DefectRateReportRow::totalOf(array_values($products))];
        }

        return $rows;
    }

    /**
     * Cột báo cáo: khoá → tiêu đề. Cả hai Vai trò xem được thấy như nhau.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'supplier' => 'Nhà cung cấp',
            'code' => 'Mã sản phẩm',
            'name' => 'Sản phẩm',
            'intake_units' => 'Đơn vị hàng nhập',
            'delivered_units' => 'Đã giao',
            'defective_units' => 'Đơn vị hàng Lỗi',
            'defect_rate' => 'Tỉ lệ lỗi',
            'defective_in_stock_units' => 'Lỗi trong kho',
            'rejected_lines' => 'Dòng lỗi/trùng khi nhập',
        ];
    }

    /**
     * File báo cáo, gồm cả dòng tổng của từng Nhà cung cấp. Không ghi nhật ký.
     *
     * @throws MissingRole
     */
    public function export(User $actor, DefectRateReportFilter $filter, ReportFormat $format): ReportExport
    {
        $rows = $this->rows($actor, $filter);
        $columns = $this->columns();

        return ReportExport::of(
            'bao-cao-ti-le-loi-'.$filter->from->toDateString().'-'.$filter->to->toDateString(),
            $format,
            array_values($columns),
            array_map(
                fn (DefectRateReportRow $row): array => array_map($row->cell(...), array_keys($columns)),
                $rows,
            ),
        );
    }

    /**
     * Một dòng cho mỗi Nhà cung cấp × Sản phẩm có Dòng nhập xác nhận trong khoảng. Lứa nhập là các
     * Dòng nhập, nên Dòng nhập mà mọi Đơn vị hàng đã bị Huỷ nhập vẫn có dòng: chất lượng file vẫn nói
     * lên điều gì đó, chỉ là không còn hàng để tính Tỉ lệ lỗi.
     */
    private static function aggregates(DefectRateReportFilter $filter): QueryBuilder
    {
        return DB::query()
            ->fromSub(self::lines($filter), 'lines')
            ->leftJoinSub(self::units($filter), 'units', fn (JoinClause $join) => $join
                ->on('units.supplier_id', '=', 'lines.supplier_id')
                ->on('units.product_id', '=', 'lines.product_id'))
            ->join('suppliers', 'suppliers.id', '=', 'lines.supplier_id')
            ->join('products', 'products.id', '=', 'lines.product_id')
            ->orderBy('suppliers.name')
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->select('lines.supplier_id', 'lines.product_id', 'lines.rejected_lines', 'products.code')
            ->selectRaw('suppliers.name AS supplier_name')
            ->selectRaw('products.name AS product_name')
            ->selectRaw(collect(['intake_units', 'delivered_units', 'defective_units', 'defective_in_stock_units'])
                ->map(fn (string $column): string => "COALESCE(units.{$column}, 0) AS {$column}")
                ->implode(', '));
    }

    /**
     * Lứa nhập của khoảng: Dòng nhập của Lô nhập xác nhận trong khoảng, kèm số dòng bị bỏ vì lỗi định
     * dạng hoặc trùng. Các dòng đó chưa từng thành Đơn vị hàng nên không vào Tỉ lệ lỗi.
     */
    private static function lines(DefectRateReportFilter $filter): QueryBuilder
    {
        return self::ofBatchesIn($filter, DB::table('batch_lines'))
            ->groupBy('batches.supplier_id', 'batch_lines.product_id')
            ->select('batches.supplier_id', 'batch_lines.product_id')
            ->selectRaw('COALESCE(SUM(batch_lines.invalid_count + batch_lines.file_duplicate_count + batch_lines.stock_duplicate_count), 0) AS rejected_lines');
    }

    /**
     * Đơn vị hàng của lứa nhập, chia theo đã giao hay chưa và có đang Lỗi hay không. Hàng Huỷ nhập bị
     * loại hẳn: coi như chưa từng vào kho.
     */
    private static function units(DefectRateReportFilter $filter): QueryBuilder
    {
        $defective = "stock_units.status = '".StockUnitStatus::Defective->value."'";
        $delivered = 'delivered.stock_unit_id IS NOT NULL';

        return self::ofBatchesIn($filter, DB::table('stock_units')
            ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id'))
            ->leftJoinSub(self::deliveredUnits(), 'delivered', 'delivered.stock_unit_id', '=', 'stock_units.id')
            ->where('stock_units.status', '<>', StockUnitStatus::Reversed->value)
            ->groupBy('batches.supplier_id', 'stock_units.product_id')
            ->select('batches.supplier_id', 'stock_units.product_id')
            ->selectRaw('COUNT(*) AS intake_units')
            ->selectRaw("COUNT(*) FILTER (WHERE {$delivered}) AS delivered_units")
            ->selectRaw("COUNT(*) FILTER (WHERE {$delivered} AND {$defective}) AS defective_units")
            ->selectRaw("COUNT(*) FILTER (WHERE NOT {$delivered} AND {$defective}) AS defective_in_stock_units");
    }

    /**
     * Đơn vị hàng đã giao ít nhất một Slot. Lần giao đã bị Giao thay không tính: Slot đó đã Huỷ hàng và
     * khách nhận Slot khác, nên hàng chưa thực sự đến tay ai để mà lộ lỗi.
     */
    private static function deliveredUnits(): QueryBuilder
    {
        return DB::table('deliveries')
            ->whereNotExists(fn (QueryBuilder $corrected) => $corrected
                ->selectRaw('1')
                ->from('deliveries AS corrections')
                ->whereColumn('corrections.corrects_delivery_id', 'deliveries.id'))
            ->groupBy('deliveries.stock_unit_id')
            ->select('deliveries.stock_unit_id');
    }

    /**
     * Giới hạn vào Lô nhập đã xác nhận trong khoảng, và vào Nhà cung cấp, Sản phẩm đang lọc. Dùng chung
     * cho cả hai truy vấn con để lứa nhập của chúng luôn là một.
     */
    private static function ofBatchesIn(DefectRateReportFilter $filter, QueryBuilder $query): QueryBuilder
    {
        return $query
            ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
            ->where('batches.status', BatchStatus::Confirmed->value)
            ->where('batches.confirmed_at', '>=', $filter->startsAt())
            ->where('batches.confirmed_at', '<', $filter->endsBefore())
            ->when($filter->supplierIds !== [], fn (QueryBuilder $ofSuppliers) => $ofSuppliers->whereIn('batches.supplier_id', $filter->supplierIds))
            ->when($filter->productIds !== [], fn (QueryBuilder $ofProducts) => $ofProducts->whereIn('batch_lines.product_id', $filter->productIds));
    }
}
