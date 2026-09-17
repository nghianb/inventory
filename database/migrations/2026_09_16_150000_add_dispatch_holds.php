<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Luồng hai bước của Kênh bán loại API: phiếu Đang giữ mang hạn Giữ hàng, và mỗi Slot Đã giữ có
     * một hàng trong `slot_holds` trỏ về Dòng xuất đang giữ nó.
     *
     * Hàng trong `slot_holds` chỉ sống đúng lúc Slot ở trạng thái Đã giữ: giao hay nhả đều xoá nó
     * đi. Không mất lịch sử, vì mọi lần chuyển trạng thái đã có dòng riêng trong Sổ biến động kho;
     * để lại bản ghi chết ở đây thì `slot_id` không còn là duy nhất được nữa.
     */
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            // Chốt lúc tạo phiếu theo hạn Giữ hàng của Kênh bán; null khi phiếu giữ và giao một bước.
            $table->timestampTz('hold_expires_at')->nullable();
        });

        // Job nhả hold chỉ quét các phiếu Đang giữ, nên index từng phần thay vì cả bảng Phiếu xuất.
        DB::statement("CREATE INDEX dispatches_expiring_holds ON dispatches (hold_expires_at) WHERE status = 'holding'");

        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_line_id')->constrained()->restrictOnDelete();
            // Một Slot chỉ được giữ cho đúng một Dòng xuất tại một thời điểm.
            $table->foreignId('slot_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->timestampTz('held_at');

            $table->index('dispatch_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_holds');
        DB::statement('DROP INDEX IF EXISTS dispatches_expiring_holds');

        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn('hold_expires_at');
        });
    }
};
