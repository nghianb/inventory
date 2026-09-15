<?php

namespace App\Inventory\Intake;

/**
 * Dạng nội dung của một Dòng nhập: văn bản dán hoặc file có dòng tiêu đề.
 */
enum IntakeSource: string
{
    case Paste = 'paste';
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Paste => 'Dán văn bản',
            self::Csv => 'File CSV',
            self::Xlsx => 'File XLSX',
        };
    }

    /**
     * Loại file theo đuôi tên file; null nếu không phải CSV hay XLSX.
     */
    public static function fromFileName(string $fileName): ?self
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'csv', 'txt' => self::Csv,
            'xlsx' => self::Xlsx,
            default => null,
        };
    }
}
