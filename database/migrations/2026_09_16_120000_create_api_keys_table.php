<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Khoá API của một Kênh bán loại API. Giá trị khoá chỉ hiện một lần lúc tạo; kho lưu
        // SHA-256 của nó (bí mật là chuỗi ngẫu nhiên dài nên không cần hàm băm chậm), đủ để
        // tra một lần theo index và không bao giờ dựng lại được giá trị gốc.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_channel_id')->constrained()->restrictOnDelete();
            $table->string('label')->nullable();
            $table->string('key_hash', 64)->unique();
            // Vài ký tự đầu của bí mật, để Quản trị nhận ra khoá nào trong danh sách.
            $table->string('prefix', 12);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Khoá mà khoá này thay thế khi xoay; hai khoá cùng hoạt động cho tới khi thu hồi khoá cũ.
            $table->foreignId('rotates_api_key_id')->nullable()->constrained('api_keys')->restrictOnDelete();
            $table->timestampTz('last_used_at')->nullable();
            // Khoá thu hồi không bị xoá: Nhật ký xem mã và Phiếu xuất còn tham chiếu tới nó.
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['sales_channel_id', 'revoked_at']);
        });

        // Nhật ký xem mã ghi "ai" là Khoá API khi nội dung được trả qua API; cột có sẵn từ
        // migration Nhật ký xem mã, giờ mới có bảng để ràng buộc khoá ngoại.
        DB::statement('ALTER TABLE reveal_log_entries ADD CONSTRAINT reveal_log_entries_api_key_id_foreign FOREIGN KEY (api_key_id) REFERENCES api_keys (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reveal_log_entries DROP CONSTRAINT reveal_log_entries_api_key_id_foreign');
        Schema::dropIfExists('api_keys');
    }
};
