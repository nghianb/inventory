<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lịch sử sửa Phiếu xuất: mỗi dòng một trường đổi giá trị. Tách khỏi Sổ biến động kho.
        Schema::create('dispatch_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->restrictOnDelete();
            // Có giá trị khi trường thuộc một Dòng xuất (Giá bán).
            $table->foreignId('dispatch_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('field');
            // Giá trị thô dạng chữ; null là để trống.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index('dispatch_id');
        });

        // Hàm reject_append_only_change() tạo ở migration Nhật ký bảo mật.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER dispatch_revisions_append_only
                BEFORE UPDATE OR DELETE ON dispatch_revisions
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_change();

            CREATE TRIGGER dispatch_revisions_no_truncate
                BEFORE TRUNCATE ON dispatch_revisions
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_revisions');
    }
};
