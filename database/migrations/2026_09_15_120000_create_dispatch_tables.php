<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->boolean('requires_external_ref')->default(false);
            // Kênh ngừng dùng thì ẩn, không xoá: Phiếu xuất cũ vẫn tham chiếu tới nó.
            $table->timestampTz('hidden_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX sales_channels_name_unique ON sales_channels (lower(name))');

        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_channel_id')->constrained()->restrictOnDelete();
            // Chiếm vĩnh viễn trong kênh khi phiếu được tạo, kể cả khi phiếu bị huỷ.
            $table->string('external_ref', 100);
            $table->text('customer')->nullable();
            $table->text('note')->nullable();
            $table->string('status');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            // Mốc màn kết quả đã hiện nội dung cho người tạo; sau mốc này xem lại là một lần xem mã riêng.
            $table->timestampTz('result_revealed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['sales_channel_id', 'external_ref']);
            $table->index('created_at');
        });

        Schema::create('dispatch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->unsignedInteger('quantity');
            // Tổng tiền VND của cả dòng, không phải đơn giá.
            $table->unsignedBigInteger('sale_price')->nullable();
            $table->timestampsTz();

            $table->index('product_id');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_line_id')->constrained()->restrictOnDelete();
            // Slot Đã giao không bao giờ về Còn hàng: mỗi Slot được giao nhiều nhất một lần.
            $table->foreignId('slot_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            // Thời hạn bảo hành của Sản phẩm tại thời điểm giao; Sản phẩm sửa sau đó không đổi giá trị này.
            $table->unsignedInteger('warranty_days');
            $table->timestampTz('delivered_at');
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('dispatch_line_id');
            $table->index('stock_unit_id');
        });

        // Số thứ tự mã đơn tự sinh PX-YYYYMMDD-NNNN theo ngày nghiệp vụ.
        Schema::create('dispatch_ref_counters', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('last_number');
        });

        DB::statement('ALTER TABLE dispatch_lines ADD CONSTRAINT dispatch_lines_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE dispatch_lines ADD CONSTRAINT dispatch_lines_sale_price_not_negative CHECK (sale_price IS NULL OR sale_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_ref_counters');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('dispatch_lines');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('sales_channels');
    }
};
