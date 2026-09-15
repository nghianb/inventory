<?php

namespace App\Models;

use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Claims\SupplierClaimStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Khiếu nại nhà cung cấp: đòi một Nhà cung cấp bồi hoàn cho các Đơn vị hàng Lỗi của họ. Chỉ tạo và
 * chuyển trạng thái qua {@see SupplierClaims}.
 *
 * @property int $id
 * @property int $supplier_id
 * @property SupplierClaimStatus $status
 * @property ?string $note
 * @property int $created_by
 * @property ?int $sent_by
 * @property ?CarbonImmutable $sent_at
 * @property ?int $resolved_by
 * @property ?CarbonImmutable $resolved_at
 * @property ?int $cancelled_by
 * @property ?CarbonImmutable $cancelled_at
 * @property ?string $cancel_reason
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 * @property-read User $creator
 * @property-read ?User $sender
 * @property-read ?User $resolver
 * @property-read ?User $canceller
 * @property-read Collection<int, SupplierClaimUnit> $claimUnits
 * @property-read Collection<int, Batch> $batches
 */
class SupplierClaim extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'status' => SupplierClaimStatus::class,
            'created_by' => 'integer',
            'sent_by' => 'integer',
            'sent_at' => 'immutable_datetime',
            'resolved_by' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Khiếu nại nhận Lô nhập hàng thay thế: Đã giải quyết, có Đơn vị hàng kết quả Hàng thay thế.
     *
     * @param  Builder<SupplierClaim>  $query
     */
    public function scopeAcceptsReplacementGoods(Builder $query): void
    {
        $query->where('status', SupplierClaimStatus::Resolved)
            ->whereHas('claimUnits', fn (Builder $units) => $units->where('active', true)->where('outcome', ClaimOutcome::ReplacementGoods));
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Mọi Đơn vị hàng từng nằm trong khiếu nại, kể cả đã gỡ.
     *
     * @return HasMany<SupplierClaimUnit, $this>
     */
    public function claimUnits(): HasMany
    {
        return $this->hasMany(SupplierClaimUnit::class)->orderBy('id');
    }

    /**
     * Lô nhập hàng thay thế.
     *
     * @return HasMany<Batch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class)->orderBy('id');
    }
}
