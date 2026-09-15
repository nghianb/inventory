<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kết quả xử lý của Báo lỗi Xác nhận: Chờ đổi → Đã đổi, hoặc Không đổi kèm lý do.
        Schema::table('defect_reports', function (Blueprint $table) {
            $table->string('resolution')->nullable();
            $table->text('resolution_note')->nullable();
            // Không đổi vì khách đã được hoàn tiền ngoài kho: Giá bán của Dòng xuất cần sửa xuống.
            $table->boolean('refunded')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            // Đổi hàng từ lần thứ 3 của chuỗi: Bán hàng yêu cầu, Quản trị duyệt.
            $table->foreignId('replacement_approval_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('replacement_approval_requested_at')->nullable();
            $table->foreignId('replacement_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('replacement_approved_at')->nullable();

            $table->index(['resolution', 'verified_at']);
        });

        DB::table('defect_reports')->where('status', 'confirmed')->update(['resolution' => 'awaiting-replacement']);

        DB::unprepared(<<<'SQL'
            ALTER TABLE defect_reports
                ADD CONSTRAINT defect_reports_resolution CHECK (
                    (status = 'confirmed') = (resolution IS NOT NULL)
                    AND (resolution IS NOT DISTINCT FROM 'not-replaced') = (resolution_note IS NOT NULL)
                    AND (NOT refunded OR resolution IS NOT DISTINCT FROM 'not-replaced')
                    AND (COALESCE(resolution, 'awaiting-replacement') <> 'awaiting-replacement') = (resolved_by IS NOT NULL)
                    AND (resolved_by IS NULL) = (resolved_at IS NULL)
                    AND (replacement_approval_requested_by IS NULL) = (replacement_approval_requested_at IS NULL)
                    AND (replacement_approved_by IS NULL) = (replacement_approved_at IS NULL)
                    AND (resolution IS NOT NULL OR (replacement_approval_requested_at IS NULL AND replacement_approved_at IS NULL))
                );
            SQL);

        // Đổi hàng: Hạn bảo hành kế thừa của lần giao gốc, không tính lại từ ngày giao.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->date('warranty_ends_on')->nullable();
        });

        Schema::create('replacements', function (Blueprint $table) {
            $table->id();
            // Mỗi Báo lỗi Đổi hàng nhiều nhất một lần.
            $table->foreignId('defect_report_id')->unique()->constrained()->restrictOnDelete();
            // Lần giao gốc của chuỗi: Đổi hàng nối tiếp vẫn trỏ về đây; sequence là lần đổi thứ mấy.
            $table->foreignId('original_delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('delivery_id')->unique()->constrained()->restrictOnDelete();
            $table->text('product_change_reason')->nullable();
            $table->boolean('short_expiry_accepted');
            // Chi phí đổi hàng: Giá vốn Slot thay thế, gắn Sản phẩm và Nhà cung cấp của Đơn vị hàng lỗi.
            $table->unsignedBigInteger('cost');
            $table->foreignId('defective_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Quản trị đã duyệt (hoặc tự làm) lần đổi từ thứ 3 của chuỗi.
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            // Mốc màn kết quả Đổi hàng đã hiện nội dung; sau đó xem lại qua Xem mã của lần giao.
            $table->timestampTz('result_revealed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['original_delivery_id', 'sequence']);
            $table->index('defective_product_id');
            $table->index('supplier_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE replacements
                ADD CONSTRAINT replacements_sequence_positive CHECK (sequence >= 1),
                ADD CONSTRAINT replacements_cost_not_negative CHECK (cost >= 0),
                ADD CONSTRAINT replacements_approved CHECK ((sequence >= 3) = (approved_by IS NOT NULL));
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('replacements');
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropColumn('warranty_ends_on'));
        DB::statement('ALTER TABLE defect_reports DROP CONSTRAINT defect_reports_resolution');
        Schema::table('defect_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('replacement_approval_requested_by');
            $table->dropConstrainedForeignId('replacement_approved_by');
            $table->dropColumn(['resolution', 'resolution_note', 'refunded', 'resolved_at', 'replacement_approval_requested_at', 'replacement_approved_at']);
        });
    }
};
