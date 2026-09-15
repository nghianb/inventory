<?php

namespace App\Models;

use App\Inventory\Warranty\ReplacementDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đổi hàng: lần giao mới thay cho Slot có Báo lỗi đã Xác nhận. Chỉ tạo qua {@see ReplacementDelivery}.
 * Giá vốn và Nhà cung cấp là dữ liệu thương mại: Bán hàng không được thấy.
 *
 * @property int $id
 * @property int $defect_report_id
 * @property int $original_delivery_id lần giao gốc của chuỗi Đổi hàng
 * @property int $sequence lần đổi thứ mấy trong chuỗi, từ 1
 * @property int $delivery_id lần giao mới
 * @property ?string $product_change_reason
 * @property bool $short_expiry_accepted
 * @property int $cost Chi phí đổi hàng: Giá vốn Slot thay thế
 * @property int $defective_product_id Sản phẩm của Đơn vị hàng lỗi
 * @property int $supplier_id Nhà cung cấp của Đơn vị hàng lỗi
 * @property int $created_by
 * @property ?CarbonImmutable $result_revealed_at
 * @property CarbonImmutable $created_at
 * @property-read DefectReport $defectReport
 * @property-read Delivery $originalDelivery
 * @property-read Delivery $delivery
 * @property-read User $creator
 */
class Replacement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'defect_report_id' => 'integer',
            'original_delivery_id' => 'integer',
            'sequence' => 'integer',
            'delivery_id' => 'integer',
            'short_expiry_accepted' => 'boolean',
            'cost' => 'integer',
            'defective_product_id' => 'integer',
            'supplier_id' => 'integer',
            'created_by' => 'integer',
            'result_revealed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DefectReport, $this>
     */
    public function defectReport(): BelongsTo
    {
        return $this->belongsTo(DefectReport::class);
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function originalDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'original_delivery_id');
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
