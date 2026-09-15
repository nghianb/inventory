<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lần giao bị ảnh hưởng: lần giao khác của một Đơn vị hàng bị Huỷ hàng cả đơn vị (hoặc chuyển Lỗi),
 * để nhân viên chủ động liên hệ khách. Không chứa nội dung; không tự Báo lỗi hay Đổi hàng.
 */
final readonly class AffectedDelivery
{
    public function __construct(
        public int $deliveryId,
        public int $dispatchId,
        public string $externalRef,
        public ?string $customer,
        public string $channelName,
        public int $slotId,
        public CarbonImmutable $deliveredAt,
    ) {}

    /**
     * Lần giao còn Đã giao của Đơn vị hàng, trừ một lần giao (lần đang Giao thay).
     *
     * @return list<self>
     */
    public static function forUnit(int $stockUnitId, ?int $exceptDeliveryId = null): array
    {
        return Delivery::query()
            ->with('dispatchLine.dispatch.salesChannel')
            ->where('stock_unit_id', $stockUnitId)
            ->when($exceptDeliveryId !== null, fn (Builder $query) => $query->whereKeyNot($exceptDeliveryId))
            ->whereHas('slot', fn (Builder $slots) => $slots->where('status', SlotStatus::Delivered))
            ->orderBy('id')
            ->get()
            ->map(fn (Delivery $delivery): self => new self(
                deliveryId: $delivery->id,
                dispatchId: $delivery->dispatchLine->dispatch->id,
                externalRef: $delivery->dispatchLine->dispatch->external_ref,
                customer: $delivery->dispatchLine->dispatch->customer,
                channelName: $delivery->dispatchLine->dispatch->salesChannel->name,
                slotId: $delivery->slot_id,
                deliveredAt: $delivery->delivered_at,
            ))
            ->values()
            ->all();
    }
}
