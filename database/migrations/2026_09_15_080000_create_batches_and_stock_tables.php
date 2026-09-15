<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('document_number')->nullable();
            $table->text('note')->nullable();
            $table->string('status');
            // Lý do kiểm tra (pha 1) thất bại, không bao giờ chứa nội dung dòng.
            $table->text('validation_error')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('batch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_cost');
            $table->string('separator', 8);
            // Văn bản dán, mã hoá bằng khoá nội dung; xoá khi Lô nhập được xác nhận.
            $table->text('pending_ciphertext')->nullable();
            $table->unsignedSmallInteger('pending_key_version')->nullable();
            // Kết quả pha 1 cho màn xem trước: dòng bị bỏ kèm lý do, mẫu đã che. Dùng json
            // (không phải jsonb) để giữ thứ tự Trường nội dung trong mẫu.
            $table->json('preview')->nullable();
            // Số dòng theo từng loại; sau khi xác nhận là số thực ghi (kiểm tra trùng lại lúc ghi).
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('file_duplicate_count')->default(0);
            $table->unsignedInteger('stock_duplicate_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('status');
            $table->unsignedBigInteger('unit_cost');
            // HMAC-SHA256 hex của Khoá chống trùng đã chuẩn hoá.
            $table->char('dedupe_hash', 64);
            // Trường nội dung không nhạy cảm, lưu rõ để hiển thị và tìm kiếm.
            $table->jsonb('content')->nullable();
            // Các trường nhạy cảm (JSON) mã hoá bằng khoá nội dung.
            $table->text('secret_ciphertext')->nullable();
            $table->unsignedSmallInteger('secret_key_version')->nullable();
            $table->timestampsTz();

            $table->index('dedupe_hash');
        });

        // Mã dùng một lần là duy nhất toàn kho mãi mãi, kể cả đã giao hay huỷ.
        DB::statement("CREATE UNIQUE INDEX stock_units_one_time_code_dedupe ON stock_units (dedupe_hash) WHERE kind = 'one-time-code'");

        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->timestampsTz();

            $table->index(['stock_unit_id', 'status']);
        });

        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            // Có giá trị thì dòng nói về Slot, không thì nói về Đơn vị hàng.
            $table->foreignId('slot_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at')->useCurrent()->index();
        });

        // Hàm reject_append_only_change() tạo ở migration Nhật ký bảo mật.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON stock_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_change();

            CREATE TRIGGER stock_ledger_entries_no_truncate
                BEFORE TRUNCATE ON stock_ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('slots');
        Schema::dropIfExists('stock_units');
        Schema::dropIfExists('batch_lines');
        Schema::dropIfExists('batches');
    }
};
