<?php

namespace App\Inventory\Dispatch;

use App\Models\Dispatch;
use App\Models\SalesChannel;
use App\Models\Slot;
use Carbon\CarbonImmutable;

/**
 * Nội dung form Ghi nhận giao bù. Slot chọn đích danh qua Khoá chống trùng; Phiếu xuất thì hoặc trỏ
 * vào một phiếu Hoàn tất đã có, hoặc tạo mới từ Kênh bán và mã đơn ngoài. {@see RecordedLostDelivery::record()}
 * kiểm tra và báo lỗi.
 */
final readonly class RecordedDeliveryDraft
{
    /**
     * @param  ?Dispatch  $dispatch  phiếu đã có; null thì tạo phiếu mới từ $channel
     * @param  ?CarbonImmutable  $deliveredAt  thời điểm thực sự giao cho khách; null là lúc này. Là
     *                                         mốc tính Hạn bảo hành, nên ghi đúng ngày đã giao
     * @param  ?string  $reason  bắt buộc: vì sao lần giao này phải ghi lại bằng tay
     */
    public function __construct(
        public ?Slot $slot,
        public ?Dispatch $dispatch = null,
        public ?SalesChannel $channel = null,
        public ?string $externalRef = null,
        public ?string $customer = null,
        public ?int $salePrice = null,
        public ?CarbonImmutable $deliveredAt = null,
        public ?string $reason = null,
    ) {}

    /**
     * Mã đơn ngoài đã bỏ khoảng trắng hai đầu; null khi để trống.
     */
    public function externalRef(): ?string
    {
        $ref = trim((string) $this->externalRef);

        return $ref === '' ? null : $ref;
    }
}
