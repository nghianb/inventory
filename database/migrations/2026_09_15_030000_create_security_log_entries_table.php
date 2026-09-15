<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_log_entries', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('occurred_at')->useCurrent()->index();
        });

        // Nhật ký bảo mật chỉ-ghi-thêm: chặn cả ở tầng DB. Hàm trigger dùng chung
        // cho các nhật ký chỉ-ghi-thêm khác nên down() không xoá nó.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_append_only_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Bảng % chỉ-ghi-thêm, không được %', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER security_log_entries_append_only
                BEFORE UPDATE OR DELETE ON security_log_entries
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_change();

            CREATE TRIGGER security_log_entries_no_truncate
                BEFORE TRUNCATE ON security_log_entries
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security_log_entries');
    }
};
