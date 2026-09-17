<?php

namespace App\Console\Commands;

use App\Inventory\Encryption\InvalidKeyConfiguration;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyPurpose;
use App\Inventory\Encryption\KeyRotation;
use App\Inventory\Encryption\KeyRotationSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Chạy trên server khi nghi khoá bị lộ hoặc khi người giữ khoá rời đi. Trước khi chạy, thêm phiên
 * bản khoá mới vào `.env` (khoá nội dung: chuyển khoá cũ sang `INVENTORY_CONTENT_PREVIOUS_KEYS`).
 * Không có nút tương ứng trong Filament (ADR 0001).
 */
#[Signature('inventory:keys:rotate {purpose : Loại khoá cần xoay: content, hmac hoặc backup}')]
#[Description('Xoay một khoá mã hoá của kho sang phiên bản mới đang cấu hình trong môi trường')]
class RotateEncryptionKey extends Command
{
    public function handle(KeyRotation $rotation): int
    {
        $purpose = KeyPurpose::tryFrom((string) $this->argument('purpose'));

        if ($purpose === null) {
            $this->error('Loại khoá phải là một trong: '.implode(', ', array_column(KeyPurpose::cases(), 'value')).'.');

            return self::FAILURE;
        }

        try {
            $summary = $rotation->rotate($purpose);
        } catch (KeyFingerprintMismatch|InvalidKeyConfiguration $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Đã xoay %s từ phiên bản %s sang %d trong %d giây.',
            $purpose->label(),
            $summary->fromVersion === null ? '(chưa đăng ký)' : (string) $summary->fromVersion,
            $summary->toVersion,
            $summary->startedAt->diffInSeconds($summary->finishedAt),
        ));

        foreach (self::followUp($purpose, $summary) as $line) {
            $this->line("  - {$line}");
        }

        return self::SUCCESS;
    }

    /**
     * Việc còn lại của Người vận hành server sau khi lệnh chạy xong.
     *
     * @return list<string>
     */
    private static function followUp(KeyPurpose $purpose, KeyRotationSummary $summary): array
    {
        return match ($purpose) {
            KeyPurpose::Content => [
                sprintf('Đã mã hoá lại %d Đơn vị hàng.', $summary->rewritten),
                sprintf(
                    'Giữ khoá cũ trong INVENTORY_CONTENT_PREVIOUS_KEYS thêm ít nhất %d giờ: nội dung Lô nhập chờ xác nhận nằm trên disk còn mã hoá bằng khoá đó, và hết hạn sau ngần ấy giờ.',
                    (int) config('inventory.intake.pending_ttl_hours'),
                ),
            ],
            KeyPurpose::Hmac => [
                sprintf('Đã tính lại Khoá chống trùng của %d Đơn vị hàng; nhập hàng đã chạy lại.', $summary->rewritten),
                'Khoá HMAC không giữ khoá cũ: bỏ hẳn giá trị cũ khỏi .env.',
            ],
            KeyPurpose::Backup => [
                'Bản backup mới dùng khoá mới; backup cũ vẫn cần khoá cũ, nên giữ khoá cũ tới khi bản backup cuối cùng dùng nó hết hạn lưu.',
            ],
        };
    }
}
