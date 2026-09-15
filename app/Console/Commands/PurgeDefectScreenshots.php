<?php

namespace App\Console\Commands;

use App\Inventory\Warranty\DefectReporting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy theo lịch mỗi giờ: xoá ảnh Báo lỗi không còn Báo lỗi nào trỏ tới.
 */
#[Signature('inventory:defect-reports:purge')]
#[Description('Xoá ảnh Báo lỗi không còn Báo lỗi nào trỏ tới')]
class PurgeDefectScreenshots extends Command
{
    public function handle(DefectReporting $reports): int
    {
        $this->info("Đã xoá {$reports->purgeOrphanScreenshots()} ảnh Báo lỗi không còn dùng.");

        return self::SUCCESS;
    }
}
