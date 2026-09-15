<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reveal_log_entries', function (Blueprint $table) {
            $table->id();
            // Tác nhân: đúng một trong nhân viên hoặc Khoá API. Bảng Khoá API chưa có nên
            // khoá ngoại của api_key_id thêm cùng bảng đó.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('api_key_id')->nullable();
            // Slot bị xem; null khi nội dung không thuộc Slot nào (dòng bị bỏ khi nhập).
            $table->foreignId('slot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained()->restrictOnDelete();
            // Ngữ cảnh xem mã và bản ghi của nó (null khi Quản trị xem hàng Còn hàng).
            $table->string('context');
            $table->unsignedBigInteger('context_id')->nullable();
            // Lý do suy ra từ ngữ cảnh hoặc lý do tự do của Quản trị. Không bao giờ chứa nội dung mã.
            $table->text('reason');
            $table->timestampTz('occurred_at')->useCurrent()->index();

            $table->index('stock_unit_id');
            $table->index(['context', 'context_id']);
        });

        // Hàm reject_append_only_change() tạo ở migration Nhật ký bảo mật.
        DB::unprepared(<<<'SQL'
            ALTER TABLE reveal_log_entries
                ADD CONSTRAINT reveal_log_entries_one_actor CHECK ((user_id IS NULL) <> (api_key_id IS NULL));

            CREATE TRIGGER reveal_log_entries_append_only
                BEFORE UPDATE OR DELETE ON reveal_log_entries
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_change();

            CREATE TRIGGER reveal_log_entries_no_truncate
                BEFORE TRUNCATE ON reveal_log_entries
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reveal_log_entries');
    }
};
