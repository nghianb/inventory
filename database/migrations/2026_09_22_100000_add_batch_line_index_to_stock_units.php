<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            // Trang xem Lô nhập hỏi "hàng của lô này giờ ra sao" bằng một query đi từ batch_lines
            // xuống stock_units rồi slots. Postgres không tự tạo index cho khoá ngoại (khác MySQL),
            // nên trước khi có dòng này mỗi lần mở trang là một seq scan cả bảng stock_units.
            $table->index('batch_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropIndex(['batch_line_id']);
        });
    }
};
