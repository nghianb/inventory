<?php

namespace App\Console\Commands;

use App\Inventory\Dispatch\DispatchFreeze;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy ngay sau khi khôi phục kho từ backup: đưa kho vào Tạm dừng xuất kho để không lần giao nào
 * chạy trước khi Quản trị đối chiếu xong các đơn phát sinh sau mốc khôi phục (ADR 0001). Chỉ Quản
 * trị tắt được, trong panel — không có lệnh tắt ở đây, để một lần chạy nhầm script không mở lại kho.
 */
#[Signature('inventory:dispatches:freeze {--reason= : Lý do ghi vào Nhật ký bảo mật}')]
#[Description('Đưa kho vào Tạm dừng xuất kho sau khi khôi phục từ backup')]
class StartDispatchFreeze extends Command
{
    private const RESTORE_REASON = 'Khôi phục từ backup; chờ Quản trị đối chiếu các đơn phát sinh sau mốc khôi phục.';

    public function handle(DispatchFreeze $freeze): int
    {
        $reason = trim((string) $this->option('reason')) ?: self::RESTORE_REASON;

        if (! $freeze->freezeFromServer($reason)) {
            $this->info('Kho đã đang Tạm dừng xuất kho; giữ nguyên lý do cũ.');

            return self::SUCCESS;
        }

        $this->info('Kho đã vào Tạm dừng xuất kho. Chỉ Quản trị tắt được, trong panel.');

        return self::SUCCESS;
    }
}
