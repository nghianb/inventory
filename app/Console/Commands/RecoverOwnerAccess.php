<?php

namespace App\Console\Commands;

use App\Inventory\Access\MissingRole;
use App\Inventory\Staff\StaffManager;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lối thoát cho Quản trị tự khoá mình ngoài hệ thống. Chỉ chạy được trên server,
 * mỗi thao tác ghi Nhật ký bảo mật.
 */
#[Signature('staff:recover-owner {email : Email của Quản trị} {--unlock : Mở khoá nhân viên} {--reset-2fa : Xoá 2FA để thiết lập lại}')]
#[Description('Khôi phục quyền truy cập cho Quản trị: mở khoá và/hoặc reset 2FA')]
class RecoverOwnerAccess extends Command
{
    public function handle(StaffManager $staff): int
    {
        $reactivate = (bool) $this->option('unlock');
        $resetTwoFactor = (bool) $this->option('reset-2fa');

        if (! $reactivate && ! $resetTwoFactor) {
            $this->error('Chọn ít nhất một thao tác: --unlock hoặc --reset-2fa.');

            return self::FAILURE;
        }

        $admin = User::firstWhere('email', $this->argument('email'));

        if (! $admin instanceof User) {
            $this->error('Không tìm thấy nhân viên với email này.');

            return self::FAILURE;
        }

        try {
            $staff->recoverOwnerAccess($admin, $reactivate, $resetTwoFactor);
        } catch (MissingRole $exception) {
            $this->error('Nhân viên này không mang Vai trò Quản trị.');

            return self::FAILURE;
        }

        $this->info("Đã khôi phục quyền truy cập cho {$admin->email}.");

        return self::SUCCESS;
    }
}
