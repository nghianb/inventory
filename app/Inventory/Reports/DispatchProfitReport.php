<?php

namespace App\Inventory\Reports;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Chi tiết Lãi/lỗ theo Phiếu xuất: mỗi Phiếu xuất có hàng giao trong kỳ một dòng, để lần ra phiếu nào
 * lãi mỏng hay lỗ.
 *
 * Cùng định nghĩa "hàng đã bán" với báo cáo theo Sản phẩm ({@see SoldLines}): Giá bán và Giá vốn của
 * một Dòng xuất rơi vào kỳ có lần Giao hàng đầu tiên của dòng, Slot giao nhầm đã Huỷ hàng không tính,
 * Slot Giao thay tính vào Dòng xuất gốc. Phiếu mà mọi Dòng xuất đều chưa ghi Giá bán không có dòng ở
 * đây: chúng nằm ở dòng 'Chưa có Giá bán' của báo cáo theo Sản phẩm.
 *
 * Chỉ Quản trị. Xem hay xuất báo cáo không ghi nhật ký.
 */
class DispatchProfitReport
{
    public function __construct(private RoleGate $roles) {}

    public function canView(User $user): bool
    {
        return $this->roles->allows($user);
    }

    /**
     * @return list<DispatchProfitRow> theo số Phiếu xuất
     *
     * @throws MissingRole
     */
    public function rows(User $actor, ProfitReportFilter $filter): array
    {
        $this->roles->authorize($actor);

        return array_map(
            fn (stdClass $record): DispatchProfitRow => DispatchProfitRow::fromRecord($record),
            self::aggregates($filter)->get()->all(),
        );
    }

    /**
     * Cột báo cáo: khoá → tiêu đề.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'dispatch' => 'Phiếu xuất',
            'external_ref' => 'Mã đơn ngoài',
            'channel' => 'Kênh bán',
            'sale_price' => 'Giá bán',
            'cogs' => 'Giá vốn',
            'gross_profit' => 'Lãi gộp',
            'replacement_cost' => 'Chi phí đổi hàng phát sinh',
        ];
    }

    /**
     * File báo cáo. Không ghi nhật ký.
     *
     * @throws MissingRole
     */
    public function export(User $actor, ProfitReportFilter $filter, ReportFormat $format): ReportExport
    {
        $columns = $this->columns();

        return ReportExport::of(
            'bao-cao-lai-lo-phieu-xuat-'.$filter->from->toDateString().'-'.$filter->to->toDateString(),
            $format,
            array_values($columns),
            array_map(
                fn (DispatchProfitRow $row): array => array_map($row->cell(...), array_keys($columns)),
                $this->rows($actor, $filter),
            ),
        );
    }

    /**
     * Một dòng cho mỗi Phiếu xuất có Dòng xuất đã ghi Giá bán giao trong kỳ.
     */
    private static function aggregates(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::query()
            ->fromSub(self::sales($filter), 'sales')
            ->join('dispatches', 'dispatches.id', '=', 'sales.dispatch_id')
            ->join('sales_channels', 'sales_channels.id', '=', 'dispatches.sales_channel_id')
            ->leftJoinSub(self::replacementCosts($filter), 'replacements', 'replacements.dispatch_id', '=', 'sales.dispatch_id')
            ->orderBy('dispatches.id')
            ->select('sales.dispatch_id', 'sales.revenue', 'sales.cogs', 'dispatches.external_ref')
            ->selectRaw('sales_channels.name AS channel_name')
            ->selectRaw('COALESCE(replacements.replacement_cost, 0) AS replacement_cost');
    }

    /**
     * Giá bán và Giá vốn theo Phiếu xuất, cộng từ các Dòng xuất đã ghi Giá bán.
     */
    private static function sales(ProfitReportFilter $filter): QueryBuilder
    {
        return SoldLines::priced($filter)
            ->groupBy('dispatch_lines.dispatch_id')
            ->selectRaw('dispatch_lines.dispatch_id AS dispatch_id');
    }

    /**
     * Chi phí đổi hàng phát sinh trong kỳ cho từng Phiếu xuất, theo lần giao gốc mà mỗi Đổi hàng bù
     * cho. Chuỗi đổi nhiều lần vẫn trỏ về lần giao gốc nên mọi lần đổi đều quy về đúng phiếu.
     */
    private static function replacementCosts(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::table('replacements')
            ->join('deliveries', 'deliveries.id', '=', 'replacements.original_delivery_id')
            ->join('dispatch_lines', 'dispatch_lines.id', '=', 'deliveries.dispatch_line_id')
            ->where('replacements.created_at', '>=', $filter->startsAt())
            ->where('replacements.created_at', '<', $filter->endsBefore())
            ->groupBy('dispatch_lines.dispatch_id')
            ->selectRaw('dispatch_lines.dispatch_id AS dispatch_id')
            ->selectRaw('COALESCE(SUM(replacements.cost), 0) AS replacement_cost');
    }
}
