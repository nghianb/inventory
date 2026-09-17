<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tạm dừng xuất kho là trạng thái của cả kho, nên bảng có đúng một hàng (id = 1) và migration tự
     * chèn nó: mọi đường đọc thấy sẵn hàng ấy, không đường nào phải xử lý trường hợp "chưa có cờ".
     *
     * Mỗi lần Giữ hàng hoặc Giao hàng đọc hàng này bằng khoá chia sẻ trong chính transaction của nó,
     * còn bật/tắt khoá độc quyền. Nhờ vậy bật tạm dừng chờ các lần giao đang chạy dở xong thay vì cắt
     * ngang chúng, và không lần giao nào bắt đầu được sau khi cờ đã bật.
     *
     * Lịch sử bật/tắt nằm ở Nhật ký bảo mật; ở đây chỉ giữ trạng thái hiện tại.
     */
    public function up(): void
    {
        Schema::create('dispatch_freeze', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->timestampTz('frozen_at')->nullable();
            $table->text('reason')->nullable();
            // null khi lệnh artisan bật sau khi khôi phục: đó là việc của server, không của nhân viên nào.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at');
        });

        DB::statement('ALTER TABLE dispatch_freeze ADD CONSTRAINT dispatch_freeze_single_row CHECK (id = 1)');
        // Đang tạm dừng thì luôn có lý do: Quản trị đọc lại sau này cần biết vì sao kho dừng.
        DB::statement('ALTER TABLE dispatch_freeze ADD CONSTRAINT dispatch_freeze_reason_with_state CHECK ((frozen_at IS NULL) = (reason IS NULL))');

        DB::table('dispatch_freeze')->insert(['id' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_freeze');
    }
};
