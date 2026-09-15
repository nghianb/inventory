<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mã đơn ngoài đã bị chiếm trong từng Kênh bán: chiếm vĩnh viễn, kể cả khi phiếu bị huỷ hay
        // sửa sang mã khác. Cột external_ref của phiếu chỉ là mã hiện tại.
        Schema::create('dispatch_external_refs', function (Blueprint $table) {
            $table->foreignId('sales_channel_id')->constrained()->restrictOnDelete();
            $table->string('external_ref', 100);
            // Phiếu xuất chiếm mã. Khoá ngoại kiểm tra cuối transaction để chiếm mã trước khi chèn phiếu.
            $table->unsignedBigInteger('dispatch_id');
            $table->timestampTz('claimed_at')->useCurrent();

            $table->primary(['sales_channel_id', 'external_ref']);
            $table->index('dispatch_id');
        });

        // Hàm reject_append_only_change() tạo ở migration Nhật ký bảo mật.
        DB::unprepared(<<<'SQL'
            ALTER TABLE dispatch_external_refs
                ADD CONSTRAINT dispatch_external_refs_dispatch_id_foreign
                FOREIGN KEY (dispatch_id) REFERENCES dispatches (id) DEFERRABLE INITIALLY DEFERRED;

            INSERT INTO dispatch_external_refs (sales_channel_id, external_ref, dispatch_id, claimed_at)
                SELECT sales_channel_id, external_ref, id, COALESCE(created_at, now()) FROM dispatches;

            CREATE TRIGGER dispatch_external_refs_append_only
                BEFORE UPDATE OR DELETE ON dispatch_external_refs
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_change();

            CREATE TRIGGER dispatch_external_refs_no_truncate
                BEFORE TRUNCATE ON dispatch_external_refs
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_external_refs');
    }
};
