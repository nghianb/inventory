<?php

namespace App\Inventory\Dispatch;

/**
 * Cấu hình một Kênh bán khi tạo hoặc sửa.
 */
final readonly class SalesChannelDraft
{
    public function __construct(
        public string $name,
        public SalesChannelType $type = SalesChannelType::Manual,
        public bool $requiresExternalRef = false,
    ) {}
}
