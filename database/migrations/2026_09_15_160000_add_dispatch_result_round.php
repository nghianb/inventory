<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Màn kết quả thuộc lần xuất kho gần nhất của phiếu: tạo phiếu hoặc Giao thêm. Mỗi lần Giao
        // thêm đặt lại người xem được, Dòng xuất đầu tiên của lần đó và mốc result_revealed_at.
        Schema::table('dispatches', function (Blueprint $table) {
            $table->foreignId('result_by')->nullable()->constrained('users')->restrictOnDelete();
            // Null: mọi Dòng xuất (lần tạo phiếu).
            $table->foreignId('result_from_line_id')->nullable()->constrained('dispatch_lines')->restrictOnDelete();
        });

        DB::statement('UPDATE dispatches SET result_by = created_by');
        DB::statement('ALTER TABLE dispatches ALTER COLUMN result_by SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('result_from_line_id');
            $table->dropConstrainedForeignId('result_by');
        });
    }
};
