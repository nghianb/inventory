<?php

namespace App\Inventory\Reports;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\SupplierClaimStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Báo cáo lỗ theo Nhà cung cấp: mỗi Nhà cung cấp một dòng, để biết nguồn hàng nào đang ăn vào lãi và
 * đòi lại được bao nhiêu.
 *
 * Dùng chung các khoản với báo cáo Lãi/lỗ ({@see AdjustmentEvents}) nên hai báo cáo không lệch nhau,
 * nhưng chỉ lấy phần lỗi **thuộc về Nhà cung cấp**: Giá vốn hàng Lỗi và Chi phí đổi hàng. Tổn thất
 * Huỷ hàng và Tổn thất hết hạn là chuyện của shop nên đứng ngoài.
 *
 * Chỉ Quản trị. Xem hay xuất báo cáo không ghi nhật ký.
 */
class SupplierLossReport
{
    public function __construct(private RoleGate $roles) {}

    public function canView(User $user): bool
    {
        return $this->roles->allows($user);
    }

    /**
     * @return list<SupplierLossRow> theo tên Nhà cung cấp
     *
     * @throws MissingRole
     */
    public function rows(User $actor, ProfitReportFilter $filter): array
    {
        $this->roles->authorize($actor);

        return array_map(
            fn (stdClass $record): SupplierLossRow => SupplierLossRow::fromRecord($record),
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
            'supplier' => 'Nhà cung cấp',
            // Cùng con số và cùng tên với cột của báo cáo Lãi/lỗ: đây là thuật ngữ của CONTEXT.md.
            'defective_loss' => 'Tổn thất hàng Lỗi',
            'replacement_cost' => 'Chi phí đổi hàng',
            'reimbursement' => 'Đã bồi hoàn tiền',
            'replacement_goods_units' => 'Bồi hoàn bằng hàng (Đơn vị hàng)',
            'net_loss' => 'Lỗ ròng',
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
            'bao-cao-lo-nha-cung-cap-'.$filter->from->toDateString().'-'.$filter->to->toDateString(),
            $format,
            array_values($columns),
            array_map(
                fn (SupplierLossRow $row): array => array_map($row->cell(...), array_keys($columns)),
                $this->rows($actor, $filter),
            ),
        );
    }

    /**
     * Một dòng cho mỗi Nhà cung cấp có khoản nào trong kỳ. Nhà cung cấp chỉ được bồi hoàn mà không mất
     * gì vẫn có dòng, vì Lỗ ròng khi đó âm và đó là thông tin thật.
     */
    private static function aggregates(ProfitReportFilter $filter): QueryBuilder
    {
        $losses = self::losses($filter);
        $goods = self::replacementGoods($filter);

        $supplierIds = DB::query()->fromSub($losses, 'losses')->select('losses.supplier_id')
            ->union(DB::query()->fromSub($goods, 'goods')->select('goods.supplier_id'));

        return DB::query()
            ->fromSub($supplierIds, 'ids')
            ->join('suppliers', 'suppliers.id', '=', 'ids.supplier_id')
            ->leftJoinSub($losses, 'losses', 'losses.supplier_id', '=', 'ids.supplier_id')
            ->leftJoinSub($goods, 'goods', 'goods.supplier_id', '=', 'ids.supplier_id')
            ->orderBy('suppliers.name')
            ->orderBy('suppliers.id')
            ->select('ids.supplier_id')
            ->selectRaw('suppliers.name AS supplier_name')
            ->selectRaw('COALESCE(losses.defective_loss, 0) AS defective_loss')
            ->selectRaw('COALESCE(losses.replacement_cost, 0) AS replacement_cost')
            ->selectRaw('COALESCE(losses.reimbursement, 0) AS reimbursement')
            ->selectRaw('COALESCE(goods.replacement_goods_units, 0) AS replacement_goods_units')
            ->when($filter->supplierId !== null, fn (QueryBuilder $only) => $only->where('ids.supplier_id', $filter->supplierId));
    }

    /**
     * Các khoản tiền gom theo Nhà cung cấp. Chỉ ba khoản gắn với chất lượng hàng họ giao; Tổn thất Huỷ
     * hàng và Tổn thất hết hạn có trong nguồn chung nhưng không lấy ở đây.
     */
    private static function losses(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::query()
            ->fromSub(AdjustmentEvents::inPeriod($filter), 'events')
            ->groupBy('events.supplier_id')
            ->select('events.supplier_id')
            ->selectRaw('COALESCE(SUM(events.defective_loss), 0) AS defective_loss')
            ->selectRaw('COALESCE(SUM(events.replacement_cost), 0) AS replacement_cost')
            ->selectRaw('COALESCE(SUM(events.reimbursement), 0) AS reimbursement')
            ->havingRaw('SUM(events.defective_loss) + SUM(events.replacement_cost) + SUM(events.reimbursement) <> 0');
    }

    /**
     * Số Đơn vị hàng được Nhà cung cấp bồi hoàn **bằng hàng** trong kỳ, theo ngày giải quyết khiếu
     * nại. Chỉ để tham khảo: hàng thay thế vào kho với Giá vốn 0 nên không ghi thu nhập riêng.
     */
    private static function replacementGoods(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::table('supplier_claim_units')
            ->join('supplier_claims', 'supplier_claims.id', '=', 'supplier_claim_units.supplier_claim_id')
            ->where('supplier_claim_units.outcome', ClaimOutcome::ReplacementGoods->value)
            ->where('supplier_claims.status', SupplierClaimStatus::Resolved->value)
            ->where('supplier_claims.resolved_at', '>=', $filter->startsAt())
            ->where('supplier_claims.resolved_at', '<', $filter->endsBefore())
            ->groupBy('supplier_claims.supplier_id')
            ->selectRaw('supplier_claims.supplier_id AS supplier_id')
            ->selectRaw('COUNT(*) AS replacement_goods_units');
    }
}
