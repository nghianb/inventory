<?php

namespace App\Inventory\Dispatch;

use App\Models\Dispatch;
use Illuminate\Support\Facades\DB;

/**
 * Phiếu xuất Đang giữ quá hạn Giữ hàng: nhả Slot về Còn hàng và chuyển phiếu sang Hết hạn giữ. Chạy
 * theo lịch mỗi phút, vì hạn Giữ hàng tính bằng phút (mặc định 15).
 *
 * Hết hạn là việc của kho chứ không của nhân viên hay Khoá API nào, nên dòng Sổ biến động kho không
 * mang tác nhân. Phiếu Hết hạn giữ đã nhả Slot nhưng chưa chết: website xác nhận thì kho thử giữ lại
 * hàng ({@see ApiDispatch::confirm()}).
 */
class HoldExpiry
{
    public function __construct(private DispatchWriter $writer) {}

    /**
     * @return int số phiếu vừa chuyển sang Hết hạn giữ
     */
    public function releaseExpired(): int
    {
        $expired = Dispatch::query()
            ->where('status', DispatchStatus::Holding)
            ->where('hold_expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        $released = 0;

        foreach ($expired as $id) {
            $released += $this->release((int) $id) ? 1 : 0;
        }

        return $released;
    }

    /**
     * Một phiếu một transaction: phiếu hỏng không chặn các phiếu còn lại, và website đang xác nhận
     * đúng phiếu ấy thì chờ ở khoá hàng Phiếu xuất chứ không thấy Slot đổi trạng thái giữa chừng.
     */
    private function release(int $dispatchId): bool
    {
        return DB::transaction(function () use ($dispatchId): bool {
            $dispatch = Dispatch::query()->lockForUpdate()->findOrFail($dispatchId);

            // Website vừa xác nhận hoặc huỷ xong trong lúc job chạy tới phiếu này.
            if ($dispatch->status !== DispatchStatus::Holding) {
                return false;
            }

            $this->writer->release(null, $dispatch, DispatchStatus::HoldExpired, "Hết hạn giữ Phiếu xuất #{$dispatch->id}");

            return true;
        });
    }
}
