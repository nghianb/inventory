<?php

namespace App\Inventory\Dispatch;

use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Replacement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Các lần Giao hàng đã có lần giao khác thay thế: Giao thay trỏ ngược qua `corrects_delivery_id`,
 * còn Đổi hàng đi qua Báo lỗi của lần giao ấy. Nội dung của chúng không còn dùng được, nên nơi nào
 * trả nội dung ra ngoài cũng phải hỏi câu này trước.
 */
final class SupersededDeliveries
{
    /**
     * @param  EloquentCollection<int, Delivery>  $deliveries
     * @return array<int, true> tra theo id lần giao
     */
    public static function among(EloquentCollection $deliveries): array
    {
        $ids = $deliveries->modelKeys();

        if ($ids === []) {
            return [];
        }

        $corrected = Delivery::query()->whereIn('corrects_delivery_id', $ids)->pluck('corrects_delivery_id');
        $replaced = DefectReport::query()
            ->whereIn('delivery_id', $ids)
            ->whereIn('id', Replacement::query()->select('defect_report_id'))
            ->pluck('delivery_id');

        return array_fill_keys([...$corrected->all(), ...$replaced->all()], true);
    }
}
