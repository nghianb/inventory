<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cấu hình riêng của Kênh bán loại API: hạn Giữ hàng và cờ bắt buộc Giá bán. Website không tự
     * đặt hai giá trị này. Kênh thủ công vẫn giữ giá trị mặc định nhưng không dùng tới.
     */
    public function up(): void
    {
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->unsignedInteger('hold_minutes')->default(15);
            $table->boolean('requires_sale_price')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->dropColumn(['hold_minutes', 'requires_sale_price']);
        });
    }
};
