<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Đơn vị hàng chuyển Lỗi (Báo lỗi Xác nhận cả Đơn vị hàng hoặc Đánh dấu Lỗi): thời điểm, bỏ khi Khôi phục.
        Schema::table('stock_units', function (Blueprint $table) {
            $table->timestampTz('defective_at')->nullable();
        });

        // Tổn thất hàng Lỗi: Slot còn trong kho lúc Đơn vị hàng chuyển Lỗi, tính Giá vốn Slot theo thời
        // điểm này; bỏ khi Khôi phục.
        Schema::table('slots', function (Blueprint $table) {
            $table->timestampTz('defective_loss_at')->nullable();
        });

        // Hàng đã Lỗi trước migration: lấy mốc từ lần chuyển Lỗi gần nhất trong Sổ biến động kho.
        DB::statement(<<<'SQL'
            UPDATE stock_units SET defective_at = COALESCE((
                SELECT MAX(occurred_at) FROM stock_ledger_entries
                WHERE stock_ledger_entries.stock_unit_id = stock_units.id
                    AND stock_ledger_entries.slot_id IS NULL
                    AND stock_ledger_entries.to_status = 'defective'
            ), updated_at)
            WHERE status = 'defective'
        SQL);
        DB::statement(<<<'SQL'
            UPDATE slots SET defective_loss_at = stock_units.defective_at
            FROM stock_units
            WHERE stock_units.id = slots.stock_unit_id
                AND stock_units.status = 'defective'
                AND slots.status = 'in-stock'
                AND (stock_units.expires_on IS NULL OR stock_units.expires_on >= CAST(stock_units.defective_at AS date))
        SQL);
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_defective_at CHECK ((status = 'defective') = (defective_at IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_units DROP CONSTRAINT IF EXISTS stock_units_defective_at');
        Schema::table('slots', fn (Blueprint $table) => $table->dropColumn('defective_loss_at'));
        Schema::table('stock_units', fn (Blueprint $table) => $table->dropColumn('defective_at'));
    }
};
