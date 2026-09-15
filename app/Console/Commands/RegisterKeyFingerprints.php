<?php

namespace App\Console\Commands;

use App\Inventory\Encryption\InvalidKeyConfiguration;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy trên server khi thiết lập kho lần đầu hoặc sau khi thêm phiên bản khoá mới.
 * Không có nút tương ứng trong Filament (ADR 0001).
 */
#[Signature('inventory:keys:register')]
#[Description('Đăng ký dấu vân tay các khoá mã hoá đang cấu hình mà DB chưa có')]
class RegisterKeyFingerprints extends Command
{
    public function handle(KeyFingerprints $fingerprints): int
    {
        try {
            $registered = $fingerprints->register();
        } catch (KeyFingerprintMismatch|InvalidKeyConfiguration $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($registered === []) {
            $this->info('Mọi khoá đang cấu hình đã có dấu vân tay trong DB.');
        }

        foreach ($registered as $key) {
            $this->info("Đã đăng ký dấu vân tay {$key->purpose->label()} phiên bản {$key->version}.");
        }

        return self::SUCCESS;
    }
}
