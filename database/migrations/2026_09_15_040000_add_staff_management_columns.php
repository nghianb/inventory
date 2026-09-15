<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Khoá nhân viên: không bao giờ xoá nhân viên, chỉ đánh dấu thời điểm bị khoá.
            $table->timestampTz('deactivated_at')->nullable()->index();
        });

        Schema::table('security_log_entries', function (Blueprint $table) {
            // user_id là nhân viên chịu tác động; actor_id là Quản trị thực hiện thao tác
            // (null khi làm từ lệnh artisan trên server).
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('security_log_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropColumn('details');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
