<?php

namespace App\Inventory\Intake;

/**
 * Một dòng dữ liệu chưa kiểm tra: giá trị thô theo định danh Trường nội dung và theo cột
 * ghi đè, hoặc lỗi định dạng phát hiện ngay khi tách dòng.
 */
final readonly class ParsedRow
{
    /**
     * @param  int  $lineNumber  số dòng trong văn bản dán hoặc trong file (tính cả dòng tiêu đề)
     * @param  array<string, string>  $cells  theo định danh Trường nội dung
     * @param  array<string, string>  $overrides  theo tên cột ghi đè (`slot`, `han_su_dung`, `gia_von`)
     */
    public function __construct(
        public int $lineNumber,
        public array $cells = [],
        public array $overrides = [],
        public ?string $error = null,
    ) {}
}
