<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mã dùng một lần duy nhất toàn kho mãi mãi, trừ Đơn vị hàng đã Huỷ nhập (nhả khoá).
        DB::unprepared(<<<'SQL'
            DROP INDEX stock_units_one_time_code_dedupe;
            CREATE UNIQUE INDEX stock_units_one_time_code_dedupe ON stock_units (dedupe_hash) WHERE kind = 'one-time-code' AND holds_dedupe_key;
            SQL);

        Schema::table('batch_lines', function (Blueprint $table) {
            // Số Đơn vị hàng đã bị Huỷ nhập; valid_count và renewal_count giữ số đã nhập.
            $table->unsignedInteger('reversed_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('batch_lines', fn (Blueprint $table) => $table->dropColumn('reversed_count'));

        DB::unprepared(<<<'SQL'
            DROP INDEX stock_units_one_time_code_dedupe;
            CREATE UNIQUE INDEX stock_units_one_time_code_dedupe ON stock_units (dedupe_hash) WHERE kind = 'one-time-code';
            SQL);
    }
};
