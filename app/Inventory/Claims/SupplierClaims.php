<?php

namespace App\Inventory\Claims;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\SupplierClaimUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Khiếu nại nhà cung cấp: Nhập kho (và Quản trị) đòi một Nhà cung cấp bồi hoàn cho các Đơn vị hàng
 * Lỗi của họ. Mỗi Đơn vị hàng nằm trong nhiều nhất một khiếu nại chưa huỷ. Thao tác cần cả Đơn vị
 * hàng và khiếu nại khoá Đơn vị hàng trước (như Khôi phục), rồi mới tới khiếu nại.
 */
class SupplierClaims
{
    /** Lý do khi Khiếu nại Đã gửi tự huỷ vì mọi Đơn vị hàng đã Khôi phục. */
    public const AUTO_CANCEL_REASON = 'Tự huỷ: mọi Đơn vị hàng đã Khôi phục.';

    public function __construct(private RoleGate $roles) {}

    /**
     * Tạo khiếu nại Nháp.
     *
     * @param  list<StockUnit>  $units
     *
     * @throws MissingRole
     * @throws InvalidSupplierClaim
     */
    public function create(User $actor, Supplier $supplier, array $units, ?string $note = null): SupplierClaim
    {
        $this->roles->authorize($actor, Role::NhapKho);

        return DB::transaction(function () use ($actor, $supplier, $units, $note): SupplierClaim {
            $locked = self::lockClaimable($supplier, $units);

            $claim = new SupplierClaim;
            $claim->forceFill([
                'supplier_id' => $supplier->getKey(),
                'status' => SupplierClaimStatus::Draft,
                'note' => self::blankToNull($note),
                'created_by' => $actor->getKey(),
            ])->save();

            self::attach($claim, $locked);

            return $claim;
        });
    }

    /**
     * Thêm Đơn vị hàng vào khiếu nại Nháp.
     *
     * @param  list<StockUnit>  $units
     *
     * @throws MissingRole
     * @throws InvalidSupplierClaim
     */
    public function addUnits(User $actor, SupplierClaim $claim, array $units): void
    {
        $this->roles->authorize($actor, Role::NhapKho);

        DB::transaction(function () use ($claim, $units): void {
            // Báo sai trạng thái trước lỗi của từng Đơn vị hàng; kiểm lại dưới khoá sau khi khoá Đơn vị hàng.
            $unlocked = SupplierClaim::query()->with('supplier')->findOrFail($claim->getKey());
            self::ensureDraft($unlocked);
            $locked = self::lockClaimable($unlocked->supplier, $units);
            $current = self::lock($claim);
            self::ensureDraft($current);

            self::attach($current, $locked);
        });
    }

    /**
     * Gỡ một Đơn vị hàng khỏi khiếu nại Nháp; dòng giữ lại làm lịch sử.
     *
     * @throws MissingRole
     * @throws InvalidSupplierClaim
     */
    public function removeUnit(User $actor, SupplierClaimUnit $claimUnit): void
    {
        $this->roles->authorize($actor, Role::NhapKho);

        DB::transaction(function () use ($claimUnit): void {
            self::ensureDraft(self::lock($claimUnit->claim()->firstOrFail()));

            SupplierClaimUnit::query()
                ->whereKey($claimUnit->getKey())
                ->where('active', true)
                ->update(['active' => false, 'removed_at' => now(), 'removal_reason' => 'Gỡ khỏi Khiếu nại Nháp', 'updated_at' => now()]);
        });
    }

    /**
     * Nháp → Đã gửi.
     *
     * @throws MissingRole
     * @throws InvalidSupplierClaim
     */
    public function send(User $actor, SupplierClaim $claim): void
    {
        $this->roles->authorize($actor, Role::NhapKho);

        DB::transaction(function () use ($actor, $claim): void {
            $current = self::lock($claim);

            if ($current->status !== SupplierClaimStatus::Draft) {
                throw new InvalidSupplierClaim('Chỉ gửi được Khiếu nại Nháp.');
            }

            if (! $current->claimUnits()->where('active', true)->exists()) {
                throw new InvalidSupplierClaim('Khiếu nại không còn Đơn vị hàng nào để gửi.');
            }

            $current->forceFill(['status' => SupplierClaimStatus::Sent, 'sent_by' => $actor->getKey(), 'sent_at' => now()])->save();
        });
    }

    /**
     * Đã gửi → Đã giải quyết, ghi kết quả cho mọi Đơn vị hàng còn trong khiếu nại.
     *
     * @param  array<int, ClaimOutcomeDraft>  $outcomes  theo id dòng khiếu nại (SupplierClaimUnit)
     *
     * @throws MissingRole
     * @throws InvalidSupplierClaim
     */
    public function resolve(User $actor, SupplierClaim $claim, array $outcomes): void
    {
        $this->roles->authorize($actor, Role::NhapKho);

        DB::transaction(function () use ($actor, $claim, $outcomes): void {
            $current = self::lock($claim);

            if ($current->status !== SupplierClaimStatus::Sent) {
                throw new InvalidSupplierClaim('Chỉ giải quyết được Khiếu nại Đã gửi.');
            }

            $rows = $current->claimUnits()->where('active', true)->get();

            if ($rows->isEmpty()) {
                throw new InvalidSupplierClaim('Khiếu nại không còn Đơn vị hàng nào để giải quyết.');
            }

            foreach ($rows as $row) {
                $outcome = $outcomes[$row->id] ?? throw new InvalidSupplierClaim("Đơn vị hàng #{$row->stock_unit_id} chưa có kết quả.");
                $refund = $outcome->outcome === ClaimOutcome::Refund;

                if ($refund) {
                    self::ensureRefund($row, $outcome);
                }

                $row->forceFill([
                    'outcome' => $outcome->outcome,
                    'refund_amount' => $refund ? $outcome->refundAmount : null,
                    'refunded_on' => $refund ? $outcome->refundedOn?->toDateString() : null,
                    'outcome_note' => self::blankToNull($outcome->note),
                ])->save();
            }

            $current->forceFill(['status' => SupplierClaimStatus::Resolved, 'resolved_by' => $actor->getKey(), 'resolved_at' => now()])->save();
        });
    }

    /**
     * Nháp hoặc Đã gửi → Đã huỷ, kèm lý do. Đơn vị hàng quay lại danh sách Lỗi chưa khiếu nại.
     *
     * @throws MissingRole
     * @throws InvalidSupplierClaim
     */
    public function cancel(User $actor, SupplierClaim $claim, string $reason): void
    {
        $this->roles->authorize($actor, Role::NhapKho);
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidSupplierClaim('Huỷ Khiếu nại phải nhập lý do.');
        }

        DB::transaction(function () use ($actor, $claim, $reason): void {
            $current = self::lock($claim);

            if (! in_array($current->status, SupplierClaimStatus::open(), true)) {
                throw new InvalidSupplierClaim('Chỉ huỷ được Khiếu nại Nháp hoặc Đã gửi.');
            }

            $current->claimUnits()->where('active', true)->update(['active' => false, 'updated_at' => now()]);
            $current->forceFill([
                'status' => SupplierClaimStatus::Cancelled,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();
        });
    }

    /**
     * Đơn vị hàng vừa Khôi phục rời mọi khiếu nại chưa giải quyết; khiếu nại Đã gửi hết Đơn vị hàng thì
     * tự huỷ, người huỷ là người Khôi phục. Không kiểm tra quyền: người gọi (Khôi phục) đã khoá Đơn vị
     * hàng và chạy trong transaction. Khiếu nại đã giải quyết giữ nguyên.
     */
    public function releaseRestored(User $actor, StockUnit $lockedUnit, string $reason): void
    {
        $claimIds = SupplierClaimUnit::query()
            ->where('stock_unit_id', $lockedUnit->getKey())
            ->where('active', true)
            ->pluck('supplier_claim_id')
            ->all();

        // Khoá khiếu nại để không giải quyết xong giữa chừng; kiểm tra lại trạng thái dưới khoá.
        $open = SupplierClaim::query()
            ->whereKey($claimIds)
            ->whereIn('status', SupplierClaimStatus::open())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        if ($open === []) {
            return;
        }

        SupplierClaimUnit::query()
            ->where('stock_unit_id', $lockedUnit->getKey())
            ->whereIn('supplier_claim_id', $open)
            ->where('active', true)
            ->update(['active' => false, 'removed_at' => now(), 'removal_reason' => $reason, 'updated_at' => now()]);

        // Khiếu nại Đã gửi không còn Đơn vị hàng nào thì không giải quyết được nữa: tự huỷ. Nháp giữ lại để sửa.
        SupplierClaim::query()
            ->whereKey($open)
            ->where('status', SupplierClaimStatus::Sent)
            ->whereDoesntHave('claimUnits', fn (Builder $units) => $units->where('active', true))
            ->update([
                'status' => SupplierClaimStatus::Cancelled->value,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancel_reason' => self::AUTO_CANCEL_REASON,
                'updated_at' => now(),
            ]);
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see addUnits()} và {@see removeUnit()}.
     */
    public function canEdit(User $actor, SupplierClaim $claim): bool
    {
        return $this->roles->allows($actor, Role::NhapKho) && $claim->status === SupplierClaimStatus::Draft;
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see send()}.
     */
    public function canSend(User $actor, SupplierClaim $claim): bool
    {
        return $this->canEdit($actor, $claim);
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see resolve()}.
     */
    public function canResolve(User $actor, SupplierClaim $claim): bool
    {
        return $this->roles->allows($actor, Role::NhapKho) && $claim->status === SupplierClaimStatus::Sent;
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see cancel()}.
     */
    public function canCancel(User $actor, SupplierClaim $claim): bool
    {
        return $this->roles->allows($actor, Role::NhapKho) && in_array($claim->status, SupplierClaimStatus::open(), true);
    }

    /**
     * Mở trang nhập Lô nhập hàng thay thế được không; để panel ẩn nút, không thay cho kiểm tra trong
     * BatchIntake.
     */
    public function canImportReplacementGoods(User $actor, SupplierClaim $claim): bool
    {
        return $this->roles->allows($actor, Role::NhapKho)
            && SupplierClaim::query()->acceptsReplacementGoods()->whereKey($claim->getKey())->exists();
    }

    /**
     * Đơn vị hàng Lỗi chưa khiếu nại: không nằm trong khiếu nại chưa giải quyết nào, và không nằm
     * trong khiếu nại đã giải quyết cho lần Lỗi hiện tại (Lỗi lại sau Khôi phục thì khiếu nại lại được).
     *
     * @return Builder<StockUnit>
     */
    public function unclaimedDefectiveUnits(): Builder
    {
        // Cùng điều kiện với SupplierClaimUnit::coversCurrentDefect().
        return StockUnit::query()
            ->where('stock_units.status', StockUnitStatus::Defective)
            ->whereNotExists(fn ($query) => $query->from('supplier_claim_units')
                ->whereColumn('supplier_claim_units.stock_unit_id', 'stock_units.id')
                ->where('supplier_claim_units.active', true)
                ->where(fn ($covers) => $covers->whereNull('supplier_claim_units.outcome')
                    ->orWhereColumn('supplier_claim_units.created_at', '>=', 'stock_units.defective_at')));
    }

    /**
     * Khoá các Đơn vị hàng theo thứ tự id và kiểm tra khiếu nại được: Lỗi, của Nhà cung cấp, chưa nằm
     * trong khiếu nại chưa huỷ nào.
     *
     * @param  list<StockUnit>  $units
     * @return list<StockUnit>
     *
     * @throws InvalidSupplierClaim
     */
    private static function lockClaimable(Supplier $supplier, array $units): array
    {
        $ids = array_values(array_unique(array_map(fn (StockUnit $unit): int => (int) $unit->getKey(), $units)));

        if ($ids === []) {
            throw new InvalidSupplierClaim('Khiếu nại phải có ít nhất một Đơn vị hàng.');
        }

        sort($ids);

        $locked = StockUnit::query()
            ->with('batchLine.batch')
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $rows = SupplierClaimUnit::query()
            ->whereIn('stock_unit_id', $ids)
            ->where('active', true)
            ->get()
            ->groupBy('stock_unit_id');

        foreach ($locked as $unit) {
            $claimedBy = $rows->get($unit->id)?->first(fn (SupplierClaimUnit $row): bool => $row->coversCurrentDefect($unit));

            if ($unit->status !== StockUnitStatus::Defective) {
                throw new InvalidSupplierClaim("Đơn vị hàng #{$unit->id} không Lỗi; chỉ khiếu nại Đơn vị hàng Lỗi.");
            }

            if ($unit->batchLine->batch->supplier_id !== (int) $supplier->getKey()) {
                throw new InvalidSupplierClaim("Đơn vị hàng #{$unit->id} không thuộc Nhà cung cấp {$supplier->name}.");
            }

            if ($claimedBy !== null) {
                throw new InvalidSupplierClaim("Đơn vị hàng #{$unit->id} đã nằm trong Khiếu nại #{$claimedBy->supplier_claim_id}.");
            }
        }

        return $locked->values()->all();
    }

    /**
     * @param  list<StockUnit>  $units
     */
    private static function attach(SupplierClaim $claim, array $units): void
    {
        $now = now();

        SupplierClaimUnit::query()->insert(array_map(fn (StockUnit $unit): array => [
            'supplier_claim_id' => $claim->id,
            'stock_unit_id' => $unit->id,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $units));
    }

    private static function lock(SupplierClaim $claim): SupplierClaim
    {
        return SupplierClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
    }

    /**
     * @throws InvalidSupplierClaim
     */
    private static function ensureDraft(SupplierClaim $claim): void
    {
        if ($claim->status !== SupplierClaimStatus::Draft) {
            throw new InvalidSupplierClaim('Chỉ sửa được Khiếu nại Nháp.');
        }
    }

    /**
     * @throws InvalidSupplierClaim
     */
    private static function ensureRefund(SupplierClaimUnit $row, ClaimOutcomeDraft $outcome): void
    {
        if ($outcome->refundAmount === null || $outcome->refundAmount <= 0) {
            throw new InvalidSupplierClaim("Bồi hoàn tiền của Đơn vị hàng #{$row->stock_unit_id} phải có số tiền lớn hơn 0.");
        }

        if ($outcome->refundedOn === null) {
            throw new InvalidSupplierClaim("Bồi hoàn tiền của Đơn vị hàng #{$row->stock_unit_id} phải có ngày.");
        }

        if ($outcome->refundedOn->startOfDay()->gt(CarbonImmutable::today())) {
            throw new InvalidSupplierClaim("Ngày bồi hoàn tiền của Đơn vị hàng #{$row->stock_unit_id} không được sau hôm nay.");
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
