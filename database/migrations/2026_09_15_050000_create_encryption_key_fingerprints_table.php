<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dấu vân tay (HMAC của một chuỗi cố định) của từng khoá mã hoá theo phiên bản.
        // Không bao giờ lưu giá trị khoá.
        Schema::create('encryption_key_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('purpose');
            $table->unsignedInteger('version');
            $table->char('fingerprint', 64);
            $table->timestampTz('registered_at')->useCurrent();

            $table->unique(['purpose', 'version']);
        });

        // Dấu vân tay đã đăng ký không được sửa hay xoá, để không ai "hợp thức hoá" một khoá sai.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER encryption_key_fingerprints_append_only
                BEFORE UPDATE OR DELETE ON encryption_key_fingerprints
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_change();

            CREATE TRIGGER encryption_key_fingerprints_no_truncate
                BEFORE TRUNCATE ON encryption_key_fingerprints
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_key_fingerprints');
    }
};
