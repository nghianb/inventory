<?php

namespace App\Inventory\Reports;

use App\Inventory\Dispatch\DispatchLineKind;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Định nghĩa dùng chung của "hàng đã bán trong kỳ" cho các báo cáo Lãi/lỗ: những **Slot khách thực
 * nhận**, quy về **Dòng xuất** chịu trách nhiệm cho chúng.
 *
 * Giá bán là tổng tiền của cả Dòng xuất chứ không phải đơn giá, nên Dòng xuất mới là đơn vị tính lãi:
 * cả Giá bán lẫn Giá vốn của một dòng rơi vào kỳ có lần Giao hàng **đầu tiên** của dòng. Báo cáo theo
 * Sản phẩm và báo cáo theo Phiếu xuất chỉ khác nhau ở chỗ gom nhóm, nên luật nằm ở đây một lần.
 */
final class SoldLines
{
    /**
     * Các Dòng xuất **đã ghi Giá bán**, kèm sẵn hai cột tiền `revenue` và `cogs`. Người gọi chỉ thêm
     * khoá gom nhóm của mình (Sản phẩm hay Phiếu xuất), nên hai báo cáo không thể tính Lãi gộp lệch
     * nhau.
     *
     * Khi lọc Nhà cung cấp, Giá bán của cả Dòng xuất được **chia đều theo Slot**: một Dòng xuất có thể
     * gồm Slot của nhiều Nhà cung cấp, mà Giá bán là tổng tiền của cả dòng chứ không phải đơn giá, nên
     * phần của mỗi Nhà cung cấp là phần Slot họ góp vào dòng đó.
     */
    public static function priced(ProfitReportFilter $filter): QueryBuilder
    {
        return self::inPeriod($filter)
            ->whereNotNull('dispatch_lines.sale_price')
            ->selectRaw('COALESCE(SUM(ROUND(dispatch_lines.sale_price * line_deliveries.counted_slots::numeric / line_deliveries.slots)), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(line_deliveries.cogs), 0) AS cogs');
    }

    /**
     * Các Dòng xuất bán hàng có lần Giao hàng đầu tiên trong kỳ, đã nối sẵn `dispatch_lines` và
     * `dispatches`, kèm Giá vốn và số Slot khách thực nhận. Chưa gom nhóm và chưa tách theo đã ghi Giá
     * bán hay chưa: người gọi làm tiếp.
     *
     * Chỉ Giao bán và Giao thêm. Dòng xuất loại Đổi hàng và Giao thay không có Giá bán theo thiết kế:
     * Giá vốn của Đổi hàng là **Chi phí đổi hàng**, còn Slot Giao thay đã được quy về Dòng xuất gốc.
     */
    public static function inPeriod(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::query()
            ->fromSub(self::byLine($filter), 'line_deliveries')
            ->join('dispatch_lines', 'dispatch_lines.id', '=', 'line_deliveries.line_id')
            ->join('dispatches', 'dispatches.id', '=', 'dispatch_lines.dispatch_id')
            ->whereIn('dispatch_lines.kind', [DispatchLineKind::Sale->value, DispatchLineKind::Additional->value])
            ->where('line_deliveries.first_delivered_at', '>=', $filter->startsAt())
            ->where('line_deliveries.first_delivered_at', '<', $filter->endsBefore())
            // Lọc Sản phẩm phải áp ngay ở đây, không chỉ ở lúc gom theo Sản phẩm: dòng 'Chưa có Giá
            // bán' cũng dựng trên truy vấn này, và nếu bỏ sót thì nó cộng mọi Sản phẩm trong khi các
            // dòng Sản phẩm đã thu hẹp — hai nửa của cùng một màn hình nói khác nhau.
            ->when($filter->productIds !== [], fn (QueryBuilder $only) => $only->whereIn('dispatch_lines.product_id', $filter->productIds))
            ->when($filter->salesChannelId !== null, fn (QueryBuilder $ofChannel) => $ofChannel
                ->where('dispatches.sales_channel_id', $filter->salesChannelId));
    }

    /**
     * Gom các lần giao về Dòng xuất mà chúng phục vụ: mốc giao đầu tiên, tổng số Slot của dòng, và
     * phần Slot được tính kèm Giá vốn của chúng.
     *
     * `slots` luôn là **mọi** Slot khách nhận của dòng, còn `counted_slots` là phần thuộc bộ lọc: chia
     * hai số đó cho nhau ra đúng phần Giá bán thuộc về Nhà cung cấp đang lọc. Không lọc thì hai số
     * bằng nhau và Giá bán vào trọn vẹn. Dòng không góp Slot nào bị loại hẳn.
     */
    private static function byLine(ProfitReportFilter $filter): QueryBuilder
    {
        $counted = $filter->supplierId === null ? 'TRUE' : 'received.supplier_id = '.$filter->supplierId;

        return DB::query()
            ->fromSub(self::receivedSlots(), 'received')
            ->groupBy('received.line_id')
            ->havingRaw("COUNT(*) FILTER (WHERE {$counted}) > 0")
            ->select('received.line_id')
            ->selectRaw('MIN(received.delivered_at) AS first_delivered_at')
            ->selectRaw('COUNT(*) AS slots')
            ->selectRaw("COUNT(*) FILTER (WHERE {$counted}) AS counted_slots")
            ->selectRaw("COALESCE(SUM(received.cost) FILTER (WHERE {$counted}), 0) AS cogs");
    }

    /**
     * Các Slot khách thực nhận, mỗi cái gắn với Dòng xuất chịu trách nhiệm cho nó và Nhà cung cấp đã
     * bán nó cho shop.
     *
     * Lần giao đã bị **Giao thay** bị loại: Slot đó đã Huỷ hàng và khách nhận Slot khác, tính cả hai
     * thì một lần bán hoá thành hai. Ngược lại, Slot giao bù quy về Dòng xuất của lần giao mà nó thay,
     * nên Giao thay sang Sản phẩm khác vẫn nằm cùng chỗ với Giá bán đã thu, thay vì để Sản phẩm gốc
     * có doanh thu không kèm Giá vốn còn Sản phẩm kia có Giá vốn không kèm doanh thu.
     */
    private static function receivedSlots(): QueryBuilder
    {
        return DB::table('deliveries')
            ->join('slots', 'slots.id', '=', 'deliveries.slot_id')
            ->join('stock_units', 'stock_units.id', '=', 'deliveries.stock_unit_id')
            ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id')
            ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
            ->leftJoin('deliveries AS corrected', 'corrected.id', '=', 'deliveries.corrects_delivery_id')
            ->whereNotExists(fn (QueryBuilder $corrections) => $corrections
                ->selectRaw('1')
                ->from('deliveries AS later')
                ->whereColumn('later.corrects_delivery_id', 'deliveries.id'))
            ->selectRaw('COALESCE(corrected.dispatch_line_id, deliveries.dispatch_line_id) AS line_id')
            ->selectRaw('deliveries.delivered_at AS delivered_at')
            ->selectRaw('slots.cost AS cost')
            ->selectRaw('batches.supplier_id AS supplier_id');
    }
}
