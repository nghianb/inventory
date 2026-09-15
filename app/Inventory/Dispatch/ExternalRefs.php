<?php

namespace App\Inventory\Dispatch;

use App\Models\SalesChannel;
use Illuminate\Support\Facades\DB;

/**
 * Mã đơn ngoài đã bị chiếm trong từng Kênh bán. Mã bị chiếm vĩnh viễn khi phiếu được tạo hoặc
 * sửa sang mã đó, kể cả khi phiếu bị huỷ hay đổi sang mã khác. Bảng chỉ-ghi-thêm.
 */
final class ExternalRefs
{
    public const MAX_LENGTH = 100;

    /**
     * Lỗi của mã đơn ngoài khi gán cho phiếu: quá dài, hoặc đã bị phiếu khác chiếm trong kênh.
     *
     * @param  ?int  $dispatchId  phiếu được gán mã; null khi tạo phiếu mới
     */
    public static function problem(?SalesChannel $channel, string $ref, ?int $dispatchId = null): ?DispatchProblem
    {
        if (mb_strlen($ref) > self::MAX_LENGTH) {
            return new DispatchProblem(sprintf('Mã đơn ngoài dài quá %d ký tự.', self::MAX_LENGTH));
        }

        if ($channel === null) {
            return null;
        }

        $holderId = self::holderId($channel->id, $ref);

        return in_array($holderId, [null, $dispatchId], true) ? null : self::taken($channel, $ref, $holderId);
    }

    /**
     * Lỗi mã đơn ngoài đã bị chiếm, kèm phiếu đã chiếm để nhân viên mở ra xem.
     */
    public static function taken(SalesChannel $channel, string $ref, ?int $holderId = null): DispatchProblem
    {
        return new DispatchProblem("Mã đơn ngoài \"{$ref}\" đã có trong Kênh bán \"{$channel->name}\".", $holderId ?? self::holderId($channel->id, $ref));
    }

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
