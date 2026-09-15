<?php

namespace App\Console\Commands;

use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy trước khi khởi động web server; thất bại thì server không khởi động.
 */
#[Signature('inventory:keys:verify')]
#[Description('Kiểm tra khoá mã hoá trong môi trường khớp dấu vân tay trong DB')]
class VerifyKeyFingerprints extends Command
{
    public function handle(KeyFingerprints $fingerprints): int
    {
        try {
            $fingerprints->verify();
        } catch (KeyFingerprintMismatch $exception) {
            $this->error('Khoá mã hoá không khớp với DB, từ chối chạy:');

            foreach ($exception->problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->info('Khoá mã hoá khớp dấu vân tay trong DB.');

        return self::SUCCESS;
    }
}
