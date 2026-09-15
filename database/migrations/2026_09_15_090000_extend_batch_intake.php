<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // Tổng tiền trên hoá đơn Nhà cung cấp, chỉ để đối chiếu với tổng Giá vốn.
            $table->unsignedBigInteger('invoice_total')->nullable();
            // "Bổ sung cho lô": Lô nhập đã xác nhận mà lô này nhập thêm hàng cho.
            $table->foreignId('supplements_batch_id')->nullable()->constrained('batches')->restrictOnDelete();
        });

        Schema::table('batch_lines', function (Blueprint $table) {
            // Nội dung chờ xác nhận chuyển ra ổ local, mã hoá, không vào backup.
            $table->dropColumn(['pending_ciphertext', 'pending_key_version']);
            $table->string('source')->default('paste');
            $table->string('file_name')->nullable();
            // Giá trị mặc định của Dòng nhập; để trống thì theo Sản phẩm.
            $table->unsignedInteger('slots')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('expires_after_days')->nullable();
            $table->unsignedInteger('renewal_count')->default(0);
            // Tổng Giá vốn phần nhập được (hợp lệ và nhập lại), sau khi áp cột gia_von.
            $table->unsignedBigInteger('total_cost')->default(0);
        });

        DB::statement('UPDATE batch_lines SET total_cost = valid_count * unit_cost');

        Schema::table('stock_units', function (Blueprint $table) {
            $table->unsignedInteger('slot_count')->default(1);
            $table->date('expires_on')->nullable();
            // Tài khoản nhập lại (gia hạn) trỏ về Đơn vị hàng cũ cùng Khoá chống trùng.
            $table->foreignId('renews_stock_unit_id')->nullable()->constrained('stock_units')->restrictOnDelete();
            // Còn chiếm Khoá chống trùng. Tài khoản nhả khoá khi được nhập lại sau khi Huỷ hàng
            // hoặc quá Hạn sử dụng.
            $table->boolean('holds_dedupe_key')->default(true);
        });

        // Tài khoản duy nhất trong số Đơn vị hàng còn chiếm khoá.
        DB::statement("CREATE UNIQUE INDEX stock_units_account_dedupe ON stock_units (dedupe_hash) WHERE kind = 'account' AND holds_dedupe_key");

        Schema::table('slots', function (Blueprint $table) {
            // Giá vốn Đơn vị hàng chia đều cho số slot; phần dư dồn vào slot đầu để tổng khớp.
            $table->unsignedBigInteger('cost')->default(0);
        });

        DB::statement('UPDATE slots SET cost = stock_units.unit_cost FROM stock_units WHERE stock_units.id = slots.stock_unit_id');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_cost_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Giá vốn không sửa được sau khi nhập (bảng %)', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_units_cost_immutable
                BEFORE UPDATE OF unit_cost ON stock_units
                FOR EACH ROW WHEN (NEW.unit_cost IS DISTINCT FROM OLD.unit_cost)
                EXECUTE FUNCTION reject_cost_change();

            CREATE TRIGGER slots_cost_immutable
                BEFORE UPDATE OF cost ON slots
                FOR EACH ROW WHEN (NEW.cost IS DISTINCT FROM OLD.cost)
                EXECUTE FUNCTION reject_cost_change();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS slots_cost_immutable ON slots;
            DROP TRIGGER IF EXISTS stock_units_cost_immutable ON stock_units;
            DROP FUNCTION IF EXISTS reject_cost_change();
            DROP INDEX IF EXISTS stock_units_account_dedupe;
            SQL);

        Schema::table('slots', fn (Blueprint $table) => $table->dropColumn('cost'));

        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renews_stock_unit_id');
            $table->dropColumn(['slot_count', 'expires_on', 'holds_dedupe_key']);
        });

        Schema::table('batch_lines', function (Blueprint $table) {
            $table->dropColumn(['source', 'file_name', 'slots', 'expires_on', 'expires_after_days', 'renewal_count', 'total_cost']);
            $table->text('pending_ciphertext')->nullable();
            $table->unsignedSmallInteger('pending_key_version')->nullable();
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplements_batch_id');
            $table->dropColumn('invoice_total');
        });
    }
};
