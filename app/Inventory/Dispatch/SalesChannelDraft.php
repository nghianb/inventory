<?php

namespace App\Inventory\Dispatch;

/**
 * Cấu hình một Kênh bán khi tạo hoặc sửa.
 */
final readonly class SalesChannelDraft
{
    /**
     * @param  ?int  $holdMinutes  hạn Giữ hàng của kênh API; null thì lấy mặc định của kho
     * @param  ?bool  $requiresSalePrice  null thì theo loại kênh: API bắt buộc, thủ công thì không
     */
    public function __construct(
        public string $name,
        public SalesChannelType $type = SalesChannelType::Manual,
        public bool $requiresExternalRef = false,
        public ?int $holdMinutes = null,
        public ?bool $requiresSalePrice = null,
    ) {}
}
