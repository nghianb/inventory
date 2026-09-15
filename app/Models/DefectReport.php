<?php

namespace App\Models;

use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectReportStatus;
use App\Inventory\Warranty\DefectResolution;
use App\Inventory\Warranty\DefectScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Báo lỗi: một Slot đã giao nhưng khách báo không dùng được. Chỉ tạo và xác minh qua
 * {@see DefectReporting}.
 *
 * @property int $id
 * @property int $delivery_id
 * @property int $slot_id
 * @property int $stock_unit_id
 * @property DefectReportStatus $status
 * @property string $description
 * @property ?string $screenshot_path
 * @property ?string $warranty_override_reason
 * @property ?int $source_defect_report_id Báo lỗi làm Đơn vị hàng chuyển Lỗi, khi đây là Báo lỗi hàng loạt
 * @property int $created_by
 * @property ?DefectScope $scope
 * @property ?string $verification_note
 * @property ?int $verified_by
 * @property ?CarbonImmutable $verified_at
 * @property ?DefectResolution $resolution Kết quả xử lý, khi Xác nhận
 * @property ?string $resolution_note lý do Không đổi
 * @property bool $refunded Không đổi vì khách đã được hoàn tiền ngoài kho
 * @property ?int $resolved_by
 * @property ?CarbonImmutable $resolved_at
 * @property ?int $replacement_approval_requested_by Bán hàng yêu cầu Quản trị duyệt Đổi hàng từ lần thứ 3
 * @property ?CarbonImmutable $replacement_approval_requested_at
 * @property ?int $replacement_approved_by Quản trị duyệt Đổi hàng từ lần thứ 3
 * @property ?CarbonImmutable $replacement_approved_at
 * @property CarbonImmutable $created_at
 * @property-read Delivery $delivery
 * @property-read Slot $slot
 * @property-read StockUnit $stockUnit
 * @property-read User $creator
 * @property-read ?User $verifier
 * @property-read ?User $resolver
 * @property-read ?User $approvalRequester
 * @property-read ?User $replacementApprover
 * @property-read ?DefectReport $source
 * @property-read ?Replacement $replacement
 */
class DefectReport extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_id' => 'integer',
            'slot_id' => 'integer',
            'stock_unit_id' => 'integer',
            'status' => DefectReportStatus::class,
            'source_defect_report_id' => 'integer',
            'created_by' => 'integer',
            'scope' => DefectScope::class,
            'verified_by' => 'integer',
            'verified_at' => 'immutable_datetime',
            'resolution' => DefectResolution::class,
            'refunded' => 'boolean',
            'resolved_by' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'replacement_approval_requested_by' => 'integer',
            'replacement_approval_requested_at' => 'immutable_datetime',
            'replacement_approved_by' => 'integer',
            'replacement_approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Danh sách Chờ đổi: Báo lỗi Xác nhận chưa Đổi hàng hay Không đổi.
     *
     * @param  Builder<DefectReport>  $query
     */
    public function scopeAwaitingReplacement(Builder $query): void
    {
        $query->where('resolution', DefectResolution::AwaitingReplacement);
    }

    /**
     * Danh sách Chờ Quản trị duyệt: Báo lỗi Chờ đổi đã được yêu cầu duyệt Đổi hàng, chưa được duyệt.
     *
     * @param  Builder<DefectReport>  $query
     */
    public function scopeAwaitingApproval(Builder $query): void
    {
        $query->where('resolution', DefectResolution::AwaitingReplacement)
            ->whereNotNull('replacement_approval_requested_at')
            ->whereNull('replacement_approved_at');
    }

    public function isAwaitingReplacement(): bool
    {
        return $this->resolution === DefectResolution::AwaitingReplacement;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replacement_approval_requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function replacementApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replacement_approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Đổi hàng của Báo lỗi, khi Đã đổi.
     *
     * @return HasOne<Replacement, $this>
     */
    public function replacement(): HasOne
    {
        return $this->hasOne(Replacement::class);
    }

    /**
     * Tồn đọng: Chờ xác minh quá số giờ cấu hình (mặc định 24) kể từ lúc tạo.
     *
     * @param  Builder<DefectReport>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', DefectReportStatus::Pending)
            ->where('created_at', '<', now()->subHours((int) config('inventory.defect.backlog_hours')));
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return BelongsTo<StockUnit, $this>
     */
    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
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
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<DefectReport, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(DefectReport::class, 'source_defect_report_id');
    }
}
