<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dòng xuất loại Ghi nhận giao bù ghi lại một lần Giao hàng đã thực sự xảy ra và đã thu tiền,
     * nên nó có Giá bán như Giao bán và Giao thêm. Chỉ Đổi hàng và Giao thay là không có.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE dispatch_lines DROP CONSTRAINT dispatch_lines_sale_price_kind');
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_lines
                ADD CONSTRAINT dispatch_lines_sale_price_kind CHECK (
                    sale_price IS NULL OR kind IN ('sale', 'additional', 'recorded')
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dispatch_lines DROP CONSTRAINT dispatch_lines_sale_price_kind');
        DB::statement(<<<'SQL'
            ALTER TABLE dispatch_lines
                ADD CONSTRAINT dispatch_lines_sale_price_kind CHECK (
                    sale_price IS NULL OR kind IN ('sale', 'additional')
                )
            SQL);
    }
};
