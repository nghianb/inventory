<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Catalog\ProductType;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Warranty\DefectReportStatus;
use App\Models\Delivery;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lần giao bị ảnh hưởng, để nhân viên chủ động liên hệ khách. Hai nguồn:
 *
 * - lần giao khác của một Đơn vị hàng bị Huỷ hàng cả đơn vị hoặc chuyển Lỗi ({@see forUnit()});
 * - lần giao Tài khoản còn trong Hạn bảo hành khi nội dung kho bị coi là đã lộ
 *   ({@see forExposedAccounts()}).
 *
 * Không chứa nội dung; không tự Báo lỗi hay Đổi hàng.
 */
final readonly class AffectedDelivery
{
    /**
     * @param  ?DefectReportStatus  $defectReportStatus  trạng thái Báo lỗi gần nhất của lần giao
     */
    public function __construct(
        public int $deliveryId,
        public int $dispatchId,
        public string $externalRef,
        public ?string $customer,
        public string $channelName,
        public int $slotId,
        public CarbonImmutable $deliveredAt,
        public ?DefectReportStatus $defectReportStatus = null,
    ) {}

    /**
     * Lần giao còn Đã giao của Đơn vị hàng, trừ một lần giao (lần đang Giao thay hoặc Báo lỗi gốc).
     *
     * @return list<self>
     */
    public static function forUnit(int $stockUnitId, ?int $exceptDeliveryId = null): array
    {
        return Delivery::query()
            ->with(['dispatchLine.dispatch.salesChannel', 'latestDefectReport'])
            ->where('stock_unit_id', $stockUnitId)
            ->when($exceptDeliveryId !== null, fn (Builder $query) => $query->whereKeyNot($exceptDeliveryId))
            ->whereHas('slot', fn (Builder $slots) => $slots->where('status', SlotStatus::Delivered))
            ->orderBy('id')
            ->get()
            ->map(self::fromDelivery(...))
            ->values()
            ->all();
    }

    /**
     * Lần giao Tài khoản còn Đã giao và còn trong Hạn bảo hành: khách vẫn đang dùng chính Tài khoản
     * ấy, nên nội dung lộ là chuyện của họ. Mã dùng một lần không vào đây — mã đã kích hoạt xong,
     * lộ ra cũng không ai dùng lại được.
     *
     * @param  ?Product  $product  chỉ một Sản phẩm; null là cả kho
     * @return list<self>
     */
    public static function forExposedAccounts(?Product $product = null): array
    {
        return self::exposedAccounts($product)->get()->map(self::fromDelivery(...))->values()->all();
    }

    /**
     * Truy vấn của {@see forExposedAccounts()}, để panel phân trang thay vì nạp cả kho vào bộ nhớ.
     *
     * Điều kiện còn trong Hạn bảo hành viết thẳng bằng SQL cho khớp từng chữ với
     * {@see Delivery::warrantyEndsOn()}: Hạn bảo hành kế thừa (Đổi hàng) được ưu tiên, nếu không thì
     * ngày giao cộng thời hạn bảo hành đã giữ lúc giao, và cả hai đều bị Hạn sử dụng cắt ngắn.
     *
     * @return Builder<Delivery>
     */
    public static function exposedAccounts(?Product $product = null): Builder
    {
        $today = CarbonImmutable::today();
        $warrantyEnd = <<<'SQL'
            LEAST(
                COALESCE(deliveries.warranty_ends_on, (deliveries.delivered_at AT TIME ZONE ?)::date + deliveries.warranty_days),
                COALESCE(stock_units.expires_on, DATE 'infinity')
            ) >= CAST(? AS date)
            SQL;

        return Delivery::query()
            // Bảng trong panel đọc cả Sản phẩm của Dòng xuất và Hạn sử dụng của Đơn vị hàng (qua
            // warrantyEndsOn()), nên nạp sẵn cả hai: thiếu là mỗi dòng thêm vài truy vấn.
            ->with(['dispatchLine.dispatch.salesChannel', 'dispatchLine.product', 'stockUnit', 'latestDefectReport'])
            ->whereHas('stockUnit', fn (Builder $units) => $units
                ->where('stock_units.kind', ProductType::Account)
                ->when($product !== null, fn (Builder $ofProduct) => $ofProduct->where('stock_units.product_id', $product?->getKey()))
                ->whereRaw($warrantyEnd, [config('app.timezone'), $today->toDateString()]))
            ->whereHas('slot', fn (Builder $slots) => $slots->where('status', SlotStatus::Delivered))
            ->orderBy('id');
    }

    private static function fromDelivery(Delivery $delivery): self
    {
        return new self(
            deliveryId: $delivery->id,
            dispatchId: $delivery->dispatchLine->dispatch->id,
            externalRef: $delivery->dispatchLine->dispatch->external_ref,
            customer: $delivery->dispatchLine->dispatch->customer,
            channelName: $delivery->dispatchLine->dispatch->salesChannel->name,
            slotId: $delivery->slot_id,
            deliveredAt: $delivery->delivered_at,
            defectReportStatus: $delivery->latestDefectReport?->status,
        );
    }

    /**
     * Dạng chữ để liên hệ khách: "Phiếu xuất SP-001 · Shopee · Anh Minh · Slot #3 · giao 15/09/2026 10:00".
     */
    public function label(): string
    {
        return sprintf(
            'Phiếu xuất %s · %s · %s · Slot #%d · giao %s',
            $this->externalRef,
            $this->channelName,
            $this->customer ?? 'Không có khách',
            $this->slotId,
            $this->deliveredAt->format('d/m/Y H:i'),
        );
    }
}
