<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('sent_at')->nullable();
            // Ngày giải quyết là mốc cộng bồi hoàn tiền vào Lãi ròng kho.
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });

        Schema::create('supplier_claim_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            // Còn nằm trong khiếu nại: tắt khi gỡ khỏi Nháp, khi Khôi phục Đơn vị hàng (khiếu nại chưa
            // giải quyết) hoặc khi khiếu nại bị huỷ. Dòng giữ lại làm lịch sử.
            $table->boolean('active')->default(true);
            $table->timestampTz('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            // Kết quả khi giải quyết. refunded_on là ngày nhận tiền để đối chiếu; Lãi ròng kho tính theo resolved_at.
            $table->string('outcome')->nullable();
            $table->unsignedBigInteger('refund_amount')->nullable();
            $table->date('refunded_on')->nullable();
            $table->text('outcome_note')->nullable();
            $table->timestampsTz();

            $table->index(['supplier_claim_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            -- Mỗi Đơn vị hàng nằm trong nhiều nhất một khiếu nại chưa giải quyết (dòng còn trong khiếu nại,
            -- chưa có kết quả). Khiếu nại đã giải quyết giữ dòng; Lỗi lại sau Khôi phục thì khiếu nại lại được.
            CREATE UNIQUE INDEX supplier_claim_units_one_open_per_unit ON supplier_claim_units (stock_unit_id) WHERE active AND outcome IS NULL;

            ALTER TABLE supplier_claim_units
                ADD CONSTRAINT supplier_claim_units_removed CHECK ((removed_at IS NULL) = (removal_reason IS NULL)),
                ADD CONSTRAINT supplier_claim_units_outcome CHECK (
                    (outcome IS NULL AND refund_amount IS NULL AND refunded_on IS NULL)
                    OR (outcome = 'refund' AND refund_amount > 0 AND refunded_on IS NOT NULL)
                    OR (outcome IN ('replacement-goods', 'rejected') AND refund_amount IS NULL AND refunded_on IS NULL)
                );
            SQL);

        Schema::table('batches', function (Blueprint $table) {
            // Hàng thay thế từ Khiếu nại nhà cung cấp: Lô nhập bình thường, Giá vốn 0.
            $table->foreignId('supplier_claim_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_claim_id'));
        Schema::dropIfExists('supplier_claim_units');
        Schema::dropIfExists('supplier_claims');
    }
};
