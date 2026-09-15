<?php

namespace App\Console\Commands;

use App\Inventory\Intake\BatchIntake;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy theo lịch mỗi giờ: Lô nhập chưa xác nhận quá hạn xác nhận (mặc định 24 giờ) chuyển
 * Quá hạn xác nhận; nội dung tạm và file upload tạm bị xoá.
 */
#[Signature('inventory:intake:purge')]
#[Description('Đánh dấu Lô nhập chưa xác nhận quá hạn xác nhận và xoá nội dung tạm')]
class PurgeExpiredBatches extends Command
{
    public function handle(BatchIntake $intake): int
    {
        $this->info("{$intake->purgeExpired()} Lô nhập vừa quá hạn xác nhận.");

        return self::SUCCESS;
    }
}
