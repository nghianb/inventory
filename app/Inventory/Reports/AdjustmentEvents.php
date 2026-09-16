<?php

namespace App\Inventory\Reports;

use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\SupplierClaimStatus;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Inventory\Stock\VoidReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Định nghĩa dùng chung của **Điều chỉnh**: những khoản làm mỏng lãi mà không đi qua một lần bán nào.
 *
 * Mỗi khoản phát sinh trong kỳ thành một dòng mang Sản phẩm, Nhà cung cấp và số tiền của đúng một
 * khoản, nên gom theo Sản phẩm (báo cáo Lãi/lỗ) hay theo Nhà cung cấp (báo cáo lỗ theo Nhà cung cấp)
 * đều chỉ còn là một phép cộng, và hai báo cáo không thể lệch nhau.
 *
 * Tất cả tính theo **thời điểm phát sinh**, không theo kỳ nhập hay kỳ bán. Hàng **Huỷ nhập** bị loại
 * khỏi mọi khoản: nó coi như chưa từng vào kho, nên không mất gì mà cũng không đòi được gì.
 */
final class AdjustmentEvents
{
    /**
     * Các khoản tiền, theo đúng thứ tự cột của mọi nhánh.
     */
    private const AMOUNTS = [
        'replacement_cost',
        'defective_loss',
        'wrong_delivery_loss',
        'content_exposed_loss',
        'discontinued_lot_loss',
        'expiry_loss',
        'reimbursement',
    ];

    /**
     * Mọi khoản Điều chỉnh phát sinh trong kỳ, mỗi khoản một dòng.
     */
    public static function inPeriod(ProfitReportFilter $filter): QueryBuilder
    {
        $events = self::replacementCosts($filter);

        foreach ([self::defectiveLosses($filter), self::voidLosses($filter), self::expiryLosses($filter), self::reimbursements($filter)] as $next) {
            $events->unionAll($next);
        }

        return $events;
    }

    /**
     * Cột tổng của các khoản, để dùng sau `fromSub(...)` với alias `events`.
     */
    public static function totals(): string
    {
        return collect(self::AMOUNTS)
            ->map(fn (string $column): string => "COALESCE(SUM(events.{$column}), 0) AS {$column}")
            ->implode(', ');
    }

    /**
     * Chi phí đổi hàng: Giá vốn Slot giao ra trong một Đổi hàng, theo lúc đổi. Gắn với Sản phẩm và Nhà
     * cung cấp của Đơn vị hàng **lỗi**, không phải của Slot thay thế, nên nó đè lên đúng nguồn hàng đã
     * gây ra lần đổi.
     */
    private static function replacementCosts(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::table('replacements')
            ->where('replacements.created_at', '>=', $filter->startsAt())
            ->where('replacements.created_at', '<', $filter->endsBefore())
            ->selectRaw('replacements.defective_product_id AS product_id')
            ->selectRaw('replacements.supplier_id AS supplier_id')
            ->selectRaw(self::amounts(['replacement_cost' => 'replacements.cost']));
    }

    /**
     * Tổn thất hàng Lỗi: Giá vốn các Slot còn trong kho mất đi khi Đơn vị hàng chuyển Lỗi. Khôi phục
     * xoá mốc này nên hàng đã khôi phục rời khỏi báo cáo, kể cả ở một kỳ đã qua.
     */
    private static function defectiveLosses(ProfitReportFilter $filter): QueryBuilder
    {
        return self::slotLosses()
            ->where('slots.defective_loss_at', '>=', $filter->startsAt())
            ->where('slots.defective_loss_at', '<', $filter->endsBefore())
            ->selectRaw(self::amounts(['defective_loss' => 'slots.cost']));
    }

    /**
     * Tổn thất Huỷ hàng, tách theo lý do: giao nhầm (Slot đã Huỷ hàng trong một Giao thay), lộ nội
     * dung, ngừng kinh doanh lô.
     */
    private static function voidLosses(ProfitReportFilter $filter): QueryBuilder
    {
        $byReason = fn (VoidReason $reason): string => "CASE WHEN slots.void_reason = '{$reason->value}' THEN slots.cost ELSE 0 END";

        return self::slotLosses()
            ->where('slots.voided_at', '>=', $filter->startsAt())
            ->where('slots.voided_at', '<', $filter->endsBefore())
            ->selectRaw(self::amounts([
                'wrong_delivery_loss' => $byReason(VoidReason::WrongDelivery),
                'content_exposed_loss' => $byReason(VoidReason::ContentExposed),
                'discontinued_lot_loss' => $byReason(VoidReason::DiscontinuedLot),
            ]));
    }

    /**
     * Tổn thất hết hạn: Slot Còn hàng của Đơn vị hàng Hoạt động đã quá Hạn sử dụng, tính vào **ngày
     * hết hạn** chứ không vào ngày phát hiện. Slot đã Huỷ hàng hay đã thành Tồn lỗi không vào đây: mỗi
     * Slot chỉ tính tổn thất một lần.
     */
    private static function expiryLosses(ProfitReportFilter $filter): QueryBuilder
    {
        return self::slotLosses()
            ->where('slots.status', SlotStatus::InStock->value)
            ->where('stock_units.status', StockUnitStatus::Active->value)
            ->whereBetween('stock_units.expires_on', [$filter->from->toDateString(), $filter->to->toDateString()])
            ->where('stock_units.expires_on', '<', CarbonImmutable::today()->toDateString())
            ->selectRaw(self::amounts(['expiry_loss' => 'slots.cost']));
    }

    /**
     * Bồi hoàn tiền từ Khiếu nại nhà cung cấp, tính theo **ngày giải quyết** khiếu nại chứ không theo
     * ngày nhận tiền. Hàng thay thế không vào đây: nó vào kho với Giá vốn 0 nên đã tự phản ánh khi bán.
     */
    private static function reimbursements(ProfitReportFilter $filter): QueryBuilder
    {
        return DB::table('supplier_claim_units')
            ->join('supplier_claims', 'supplier_claims.id', '=', 'supplier_claim_units.supplier_claim_id')
            ->join('stock_units', 'stock_units.id', '=', 'supplier_claim_units.stock_unit_id')
            // Hàng Huỷ nhập coi như chưa từng vào kho, nên khoản đòi được trên nó cũng không còn nghĩa.
            ->where('stock_units.status', '<>', StockUnitStatus::Reversed->value)
            ->where('supplier_claim_units.outcome', ClaimOutcome::Refund->value)
            ->where('supplier_claims.status', SupplierClaimStatus::Resolved->value)
            ->where('supplier_claims.resolved_at', '>=', $filter->startsAt())
            ->where('supplier_claims.resolved_at', '<', $filter->endsBefore())
            ->selectRaw('stock_units.product_id AS product_id')
            ->selectRaw('supplier_claims.supplier_id AS supplier_id')
            ->selectRaw(self::amounts(['reimbursement' => 'supplier_claim_units.refund_amount']));
    }

    /**
     * Khung chung của các khoản tổn thất tính trên Slot: Sản phẩm của Đơn vị hàng và Nhà cung cấp của
     * Lô nhập mà nó thuộc về. Người gọi thêm mốc thời gian và số tiền của khoản mình tính.
     */
    private static function slotLosses(): QueryBuilder
    {
        return DB::table('slots')
            ->join('stock_units', 'stock_units.id', '=', 'slots.stock_unit_id')
            ->join('batch_lines', 'batch_lines.id', '=', 'stock_units.batch_line_id')
            ->join('batches', 'batches.id', '=', 'batch_lines.batch_id')
            // Hàng Huỷ nhập coi như chưa từng vào kho nên không phải tổn thất.
            ->where('stock_units.status', '<>', StockUnitStatus::Reversed->value)
            ->selectRaw('stock_units.product_id AS product_id')
            ->selectRaw('batches.supplier_id AS supplier_id');
    }

    /**
     * Cột số tiền của một nhánh: khoản nào nhánh này tính thì lấy biểu thức tương ứng, còn lại là 0.
     * Mọi nhánh phải cùng thứ tự cột thì mới hợp nhất (UNION ALL) được với nhau.
     *
     * @param  array<string, string>  $expressions  khoá cột → biểu thức SQL
     */
    private static function amounts(array $expressions): string
    {
        return collect(self::AMOUNTS)
            ->map(fn (string $column): string => ($expressions[$column] ?? '0')." AS {$column}")
            ->implode(', ');
    }
}
