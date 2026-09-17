<?php

namespace App\Console\Commands;

use App\Inventory\Dispatch\HoldExpiry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy theo lịch mỗi phút: Phiếu xuất Đang giữ quá hạn Giữ hàng nhả Slot về Còn hàng và chuyển sang
 * Hết hạn giữ. Mỗi phút một lần vì hạn Giữ hàng tính bằng phút, khác các job dọn dẹp chạy mỗi giờ.
 */
#[Signature('inventory:dispatches:release-holds')]
#[Description('Nhả Slot của các Phiếu xuất quá hạn Giữ hàng')]
class ReleaseExpiredHolds extends Command
{
    public function handle(HoldExpiry $holds): int
    {
        $this->info("{$holds->releaseExpired()} Phiếu xuất vừa Hết hạn giữ.");

        return self::SUCCESS;
    }
}
