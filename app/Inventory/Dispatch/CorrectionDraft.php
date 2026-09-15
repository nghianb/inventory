<?php

namespace App\Inventory\Dispatch;

use App\Models\Product;

/**
 * Yêu cầu Giao thay một lần giao nhầm.
 */
final readonly class CorrectionDraft
{
    /**
     * @param  ?Product  $product  Sản phẩm giao thay; null là cùng Sản phẩm với lần giao bị huỷ
     * @param  bool  $contentSent  nội dung lần giao nhầm đã gửi cho khách chưa
     * @param  bool  $voidUnit  Huỷ hàng cả Đơn vị hàng; chỉ khi nội dung đã gửi
     * @param  ?string  $reason  lý do, bắt buộc khi Quản trị Giao thay quá hạn
     */
    public function __construct(
        public ?Product $product,
        public bool $contentSent,
        public bool $voidUnit = false,
        public ?string $reason = null,
    ) {}
}
