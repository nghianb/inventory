<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dòng xuất loại Đổi hàng và Giao thay không có Giá bán: Chi phí đổi hàng trừ vào Lãi ròng kho
     * chứ không vào Lãi gộp, còn Slot Giao thay đã quy về Dòng xuất gốc, nên tiền ghi ở đây không có
     * Slot nào đối ứng trong phần Xuất của báo cáo. DispatchEditor chặn đường ghi qua Sửa phiếu; ràng
     * buộc này giữ bất biến cho mọi đường ghi khác — lệnh artisan, seeder, sửa tay. Dữ liệu cũ đã dọn
     * ở 2026_09_16_100000_clear_sale_price_on_lines_without_one.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_lines
                ADD CONSTRAINT dispatch_lines_sale_price_kind CHECK (
                    sale_price IS NULL OR kind IN ('sale', 'additional')
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dispatch_lines DROP CONSTRAINT dispatch_lines_sale_price_kind');
    }
};
