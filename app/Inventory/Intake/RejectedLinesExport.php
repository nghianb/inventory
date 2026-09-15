<?php

namespace App\Inventory\Intake;

/**
 * File CSV các dòng bị bỏ của một Dòng nhập cho một lần tải đã ghi Nhật ký xem mã. Ở màn xem
 * trước được dựng từ nội dung tạm; sau khi xác nhận đọc từ bản mã hoá chỉ giữ trong thời hạn
 * tải ngay sau xác nhận.
 */
final readonly class RejectedLinesExport
{
    public function __construct(
        public string $fileName,
        public string $csv,
    ) {}
}
