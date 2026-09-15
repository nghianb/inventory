<?php

namespace App\Models;

use App\Inventory\Claims\ClaimOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một Đơn vị hàng Lỗi trong Khiếu nại nhà cung cấp, kèm kết quả khi giải quyết.
 *
 * @property int $id
 * @property int $supplier_claim_id
 * @property int $stock_unit_id
 * @property bool $active còn nằm trong khiếu nại chưa huỷ
 * @property ?CarbonImmutable $removed_at
 * @property ?string $removal_reason
 * @property ?ClaimOutcome $outcome
 * @property ?int $refund_amount VND, khi Bồi hoàn tiền
 * @property ?CarbonImmutable $refunded_on
 * @property ?string $outcome_note
 * @property CarbonImmutable $created_at
 * @property-read SupplierClaim $claim
 * @property-read StockUnit $stockUnit
 */
class SupplierClaimUnit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_claim_id' => 'integer',
            'stock_unit_id' => 'integer',
            'active' => 'boolean',
            'removed_at' => 'immutable_datetime',
            'outcome' => ClaimOutcome::class,
            'refund_amount' => 'integer',
            'refunded_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Dòng này còn khiếu nại cho lần Lỗi hiện tại của Đơn vị hàng: còn trong khiếu nại và chưa giải
     * quyết, hoặc đã giải quyết nhưng tạo sau lúc Đơn vị hàng chuyển Lỗi (không phải lần Lỗi trước Khôi
     * phục). Cùng điều kiện với SupplierClaims::unclaimedDefectiveUnits().
     */
    public function coversCurrentDefect(StockUnit $unit): bool
    {
        return $this->active
            && ($this->outcome === null || $unit->defective_at === null || $this->created_at->gte($unit->defective_at));
    }

    /**
     * @return BelongsTo<SupplierClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(SupplierClaim::class, 'supplier_claim_id');
    }

    /**
     * @return BelongsTo<StockUnit, $this>
     */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
