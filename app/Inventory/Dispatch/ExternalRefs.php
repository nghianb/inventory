<?php

namespace App\Inventory\Dispatch;

use Illuminate\Support\Facades\DB;

/**
 * Mã đơn ngoài đã bị chiếm trong từng Kênh bán. Mã bị chiếm vĩnh viễn khi phiếu được tạo hoặc
 * sửa sang mã đó, kể cả khi phiếu bị huỷ hay đổi sang mã khác. Bảng chỉ-ghi-thêm.
 */
final class ExternalRefs
{
    /**
     * Phiếu xuất đã chiếm mã này trong kênh; null khi mã còn trống.
     */
    public static function holderId(int $channelId, string $ref): ?int
    {
        $id = DB::table('dispatch_external_refs')
            ->where('sales_channel_id', $channelId)
            ->where('external_ref', $ref)
            ->value('dispatch_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Chiếm mã cho phiếu, trong transaction của người gọi. Dùng ON CONFLICT nên mã đã bị chiếm không
     * làm hỏng transaction; giao dịch khác đang chiếm cùng mã thì chờ nó xong rồi mới trả kết quả.
     *
     * @return bool mã thuộc phiếu này: vừa chiếm được, hoặc chính phiếu này đã chiếm từ trước
     */
    public static function claim(int $channelId, string $ref, int $dispatchId): bool
    {
        $claimed = DB::table('dispatch_external_refs')->insertOrIgnoreReturning([[
            'sales_channel_id' => $channelId,
            'external_ref' => $ref,
            'dispatch_id' => $dispatchId,
            'claimed_at' => now(),
        ]], ['dispatch_id'], ['sales_channel_id', 'external_ref']);

        return $claimed->isNotEmpty() || self::holderId($channelId, $ref) === $dispatchId;
    }
}
