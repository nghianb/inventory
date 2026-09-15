<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Huỷ hàng: lý do (không phải lỗi hàng) và thời điểm, để tính Tổn thất Huỷ hàng theo lý do.
        // Ai huỷ và ghi chú nằm trong Sổ biến động kho.
        Schema::table('stock_units', function (Blueprint $table) {
            $table->string('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
        });

        Schema::table('slots', function (Blueprint $table) {
            $table->string('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
        });

        // Giao thay: lần giao mới trỏ về lần giao bị huỷ; mỗi lần giao bị Giao thay nhiều nhất một lần.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('corrects_delivery_id')->nullable()->unique()->constrained('deliveries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropConstrainedForeignId('corrects_delivery_id'));
        Schema::table('slots', fn (Blueprint $table) => $table->dropColumn(['void_reason', 'voided_at']));
        Schema::table('stock_units', fn (Blueprint $table) => $table->dropColumn(['void_reason', 'voided_at']));
    }
};
