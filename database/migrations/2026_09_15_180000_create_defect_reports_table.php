<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defect_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            // Chép từ lần giao để khoá Báo lỗi theo Slot và tạm ngừng bán theo Đơn vị hàng.
            $table->foreignId('slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->text('description');
            // Ảnh khách gửi, trên disk private của app.
            $table->string('screenshot_path')->nullable();
            // Quản trị tạo Báo lỗi ngoài Hạn bảo hành (hoặc thời hạn bảo hành 0) phải ghi lý do.
            $table->text('warranty_override_reason')->nullable();
            // Báo lỗi hàng loạt tự Xác nhận cho Lần giao bị ảnh hưởng: trỏ về Báo lỗi làm Đơn vị hàng chuyển Lỗi.
            $table->foreignId('source_defect_report_id')->nullable()->constrained('defect_reports')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('scope')->nullable();
            $table->text('verification_note')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->index('delivery_id');
            $table->index('stock_unit_id');
            $table->index(['status', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            -- Mỗi Slot tối đa một Báo lỗi Chờ xác minh hoặc Xác nhận; tạo lại được sau Bác bỏ.
            CREATE UNIQUE INDEX defect_reports_one_open_per_slot ON defect_reports (slot_id) WHERE status <> 'rejected';

            ALTER TABLE defect_reports
                ADD CONSTRAINT defect_reports_verified CHECK (
                    (status = 'pending' AND scope IS NULL AND verified_by IS NULL AND verified_at IS NULL AND verification_note IS NULL)
                    OR (status = 'confirmed' AND scope IS NOT NULL AND verified_by IS NOT NULL AND verified_at IS NOT NULL AND verification_note IS NOT NULL)
                    OR (status = 'rejected' AND scope IS NULL AND verified_by IS NOT NULL AND verified_at IS NOT NULL AND verification_note IS NOT NULL)
                );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_reports');
    }
};
