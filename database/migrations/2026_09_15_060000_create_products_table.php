<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedInteger('default_slots');
            $table->unsignedInteger('warranty_days');
            $table->unsignedInteger('min_remaining_days');
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->boolean('case_insensitive');
            $table->boolean('strip_separators');
            // Mốc Sản phẩm có Đơn vị hàng đầu tiên. Đơn vị hàng không bao giờ bị xoá
            // (Huỷ nhập chỉ đánh dấu), nên đã có hàng một lần là có hàng mãi mãi.
            $table->timestampTz('stocked_at')->nullable();
            $table->timestampTz('discontinued_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('product_content_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('type');
            $table->string('pattern')->nullable();
            $table->boolean('required');
            $table->boolean('sensitive');
            $table->boolean('is_dedupe_key');
            $table->unsignedInteger('position');
            $table->timestampsTz();

            $table->unique(['product_id', 'key']);
        });

        // Mỗi Sản phẩm có nhiều nhất một Khoá chống trùng; "đúng một" do module Kho kiểm tra.
        DB::statement('CREATE UNIQUE INDEX product_content_fields_one_dedupe_key ON product_content_fields (product_id) WHERE is_dedupe_key');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_content_fields');
        Schema::dropIfExists('products');
    }
};
