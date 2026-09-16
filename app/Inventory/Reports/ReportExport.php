<?php

namespace App\Inventory\Reports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * File báo cáo cho một lần tải: một dòng tiêu đề rồi các dòng dữ liệu. Sinh lúc tải, không lưu trên
 * server, không ghi Nhật ký xem mã hay Nhật ký bảo mật (báo cáo không chứa nội dung mã).
 */
final readonly class ReportExport
{
    public function __construct(
        public string $fileName,
        public string $contentType,
        public string $contents,
    ) {}

    /**
     * @param  string  $baseName  tên file không kèm đuôi
     * @param  list<string>  $header
     * @param  list<list<string|int|null>>  $rows
     */
    public static function of(string $baseName, ReportFormat $format, array $header, array $rows): self
    {
        return new self(
            "{$baseName}.{$format->value}",
            $format->contentType(),
            match ($format) {
                ReportFormat::Csv => self::csv($header, $rows),
                ReportFormat::Xlsx => self::xlsx($header, $rows),
            },
        );
    }

    /**
     * CSV UTF-8 có BOM để Excel đọc đúng.
     *
     * @param  list<string>  $header
     * @param  list<list<string|int|null>>  $rows
     */
    private static function csv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        try {
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ([$header, ...$rows] as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }

            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|null>>  $rows
     */
    private static function xlsx(array $header, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report');
        assert($path !== false);

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues($header));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
