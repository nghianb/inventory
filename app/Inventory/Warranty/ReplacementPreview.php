<?php

namespace App\Inventory\Warranty;

use Carbon\CarbonImmutable;

/**
 * Tóm tắt cho modal Đổi hàng: Hạn bảo hành kế thừa, lần đổi thứ mấy trong chuỗi và Slot sẽ được
 * chọn cho Sản phẩm đang xem. Không khoá gì: lúc Đổi hàng có thể chọn Slot khác.
 */
final readonly class ReplacementPreview
{
    /**
     * @param  ?bool  $coversWarranty  Slot sẽ chọn có Hạn sử dụng phủ Hạn bảo hành kế thừa không; null khi hết hàng
     * @param  ?CarbonImmutable  $candidateExpiresOn  Hạn sử dụng của Slot sẽ chọn; null khi không có hạn hoặc hết hàng
     */
    public function __construct(
        public string $productName,
        public string $unitLabel,
        public CarbonImmutable $warrantyEndsOn,
        public int $sequence,
        public bool $requiresApproval,
        public ?bool $coversWarranty,
        public ?CarbonImmutable $candidateExpiresOn,
    ) {}
}
