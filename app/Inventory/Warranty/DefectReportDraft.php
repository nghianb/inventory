<?php

namespace App\Inventory\Warranty;

use Illuminate\Http\UploadedFile;

/**
 * Yêu cầu tạo Báo lỗi, dùng chung cho mọi Slot được chọn.
 */
final readonly class DefectReportDraft
{
    /**
     * @param  string  $description  mô tả lỗi khách báo, bắt buộc
     * @param  ?UploadedFile  $screenshot  ảnh khách gửi; chỉ được lưu khi Báo lỗi tạo thành công
     * @param  ?string  $overrideReason  lý do Quản trị vượt Hạn bảo hành
     */
    public function __construct(
        public string $description,
        public ?UploadedFile $screenshot = null,
        public ?string $overrideReason = null,
    ) {}
}
