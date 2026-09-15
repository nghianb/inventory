<?php

namespace App\Inventory\Warranty;

/**
 * Yêu cầu tạo Báo lỗi, dùng chung cho mọi Slot được chọn.
 */
final readonly class DefectReportDraft
{
    /**
     * @param  string  $description  mô tả lỗi khách báo, bắt buộc
     * @param  ?string  $screenshotPath  ảnh đã lưu trên disk private
     * @param  ?string  $overrideReason  lý do Quản trị vượt Hạn bảo hành
     */
    public function __construct(
        public string $description,
        public ?string $screenshotPath = null,
        public ?string $overrideReason = null,
    ) {}
}
