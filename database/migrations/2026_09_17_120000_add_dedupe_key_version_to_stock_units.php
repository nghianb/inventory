<?php

use App\Inventory\Encryption\InvalidKeyConfiguration;
use App\Inventory\Encryption\KeyPurpose;
use App\Inventory\Encryption\KeyRing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            // Phiên bản khoá HMAC đã tính ra dedupe_hash của hàng này. Kho không bao giờ giữ song
            // song hai hash: lệnh xoay khoá HMAC tính lại từng chunk, và nhập hàng tạm dừng chừng
            // nào còn bản ghi ở phiên bản cũ, nên chống trùng không âm thầm sai giữa chừng.
            $table->unsignedSmallInteger('dedupe_key_version')->default(1);
            // Chỉ để nhập hàng hỏi "còn bản ghi nào ở phiên bản khác không" bằng min/max, không quét bảng.
            $table->index('dedupe_key_version');
        });

        // Hàng đã có trong kho được hash bằng chính khoá HMAC đang cấu hình.
        DB::table('stock_units')->update(['dedupe_key_version' => self::currentVersion()]);
    }

    public function down(): void
    {
        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropIndex(['dedupe_key_version']);
            $table->dropColumn('dedupe_key_version');
        });
    }

    /**
     * Môi trường chưa cấu hình khoá HMAC thì kho chưa nhập được hàng nào, nên phiên bản nào cũng đúng.
     */
    private static function currentVersion(): int
    {
        try {
            return app(KeyRing::class)->current(KeyPurpose::Hmac)->version;
        } catch (InvalidKeyConfiguration) {
            return 1;
        }
    }
};
