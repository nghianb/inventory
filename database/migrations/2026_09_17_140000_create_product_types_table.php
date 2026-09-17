<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Dạng hàng: Mã dùng một lần hay Tài khoản.
            $table->string('form');
            $table->boolean('case_insensitive');
            $table->boolean('strip_separators');
            // Mẫu giao hàng của Loại; Sản phẩm không ghi đè thì dùng mẫu này.
            $table->text('delivery_template')->nullable();
            // Ngừng dùng: ẩn khỏi ô chọn khi tạo Sản phẩm mới, Sản phẩm cũ không đổi.
            $table->timestampTz('discontinued_at')->nullable();
            $table->timestampsTz();
        });

        // Tên Loại là duy nhất, không phân biệt hoa thường, như Nhà cung cấp.
        DB::statement('CREATE UNIQUE INDEX product_types_name_unique ON product_types (lower(name))');

        // Trường nội dung đổi chủ từ Sản phẩm sang Loại sản phẩm. Kho chưa chạy thật nên
        // không có dữ liệu để chuyển: cột mới NOT NULL ngay, không nhánh backfill.
        Schema::table('product_content_fields', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'key']);
            $table->dropConstrainedForeignId('product_id');
            $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
            $table->unique(['product_type_id', 'key']);
        });

        // Mỗi Loại có nhiều nhất một Khoá chống trùng; "đúng một" do module Kho kiểm tra.
        DB::statement('DROP INDEX IF EXISTS product_content_fields_one_dedupe_key');
        DB::statement('CREATE UNIQUE INDEX product_content_fields_one_dedupe_key ON product_content_fields (product_type_id) WHERE is_dedupe_key');

        Schema::table('products', function (Blueprint $table) {
            // Xoá Loại đang có Sản phẩm là mất khai báo của hàng đã nhập: DB chặn luôn.
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            // Dạng hàng và hai cờ chuẩn hoá do Loại sản phẩm khai, Sản phẩm không lệch được.
            $table->dropColumn(['type', 'case_insensitive', 'strip_separators']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_type_id');
            $table->string('type');
            $table->boolean('case_insensitive');
            $table->boolean('strip_separators');
        });

        DB::statement('DROP INDEX IF EXISTS product_content_fields_one_dedupe_key');

        Schema::table('product_content_fields', function (Blueprint $table) {
            $table->dropUnique(['product_type_id', 'key']);
            $table->dropConstrainedForeignId('product_type_id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['product_id', 'key']);
        });

        DB::statement('CREATE UNIQUE INDEX product_content_fields_one_dedupe_key ON product_content_fields (product_id) WHERE is_dedupe_key');

        Schema::dropIfExists('product_types');
    }
};
