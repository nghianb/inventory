<?php

namespace App\Inventory\Dispatch;

use Carbon\CarbonImmutable;

/**
 * Một lần Giao hàng của một Phiếu xuất, như website thấy.
 *
 * Nội dung chỉ có khi lần giao còn hiệu lực và còn trong Hạn bảo hành. Các lần giao khác vẫn được
 * liệt kê kèm trạng thái và lần giao mà chúng thay, để trang đơn của khách kể đúng lịch sử — chỉ là
 * không kèm nội dung: mã đã bị thay không còn dùng được, trả ra chỉ làm khách nhầm.
 */
final readonly class ApiDelivery
{
    public function __construct(
        public int $id,
        public string $productCode,
        public ApiDeliveryStatus $status,
        /** Lần giao mà lần này thay thế (Đổi hàng hoặc Giao thay); null khi đây là lần giao đầu. */
        public ?int $replacesDeliveryId,
        public ?CarbonImmutable $expiresOn,
        public CarbonImmutable $warrantyEndsOn,
        public ?DeliveredContent $content,
    ) {}
}
