<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phiếu xuất của Kênh bán loại API do một Khoá API tạo, không phải nhân viên: "ai" của phiếu và
     * của Sổ biến động kho là Khoá API. Màn kết quả xuất kho là màn của nhân viên nên phiếu API
     * không có người xem kết quả.
     */
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->foreignId('created_by_api_key_id')->nullable()->constrained('api_keys')->restrictOnDelete();
        });

        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE dispatches ALTER COLUMN created_by DROP NOT NULL');
        DB::statement('ALTER TABLE dispatches ALTER COLUMN result_by DROP NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE dispatches
                ADD CONSTRAINT dispatches_one_creator CHECK ((created_by IS NULL) <> (created_by_api_key_id IS NULL))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE stock_ledger_entries
                ADD CONSTRAINT stock_ledger_entries_one_actor CHECK (actor_id IS NULL OR api_key_id IS NULL)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_ledger_entries DROP CONSTRAINT stock_ledger_entries_one_actor');
        DB::statement('ALTER TABLE dispatches DROP CONSTRAINT dispatches_one_creator');

        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_key_id');
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_api_key_id');
        });

        DB::statement('ALTER TABLE dispatches ALTER COLUMN created_by SET NOT NULL');
        DB::statement('ALTER TABLE dispatches ALTER COLUMN result_by SET NOT NULL');
    }
};
