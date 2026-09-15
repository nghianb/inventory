<?php

namespace App\Inventory\Dispatch;

use SensitiveParameter;

/**
 * File tải từ màn kết quả xuất kho cho một lần tải đã ghi Nhật ký xem mã. Sinh lúc tải, không
 * lưu trên server.
 */
final readonly class DispatchResultExport
{
    public function __construct(
        public string $fileName,
        public string $contentType,
        #[SensitiveParameter] public string $contents,
    ) {}

    /**
     * @param  list<DeliveredContent>  $slots
     */
    public static function of(int $dispatchId, DispatchResultFormat $format, array $slots): self
    {
        return new self(
            "phieu-xuat-{$dispatchId}.{$format->value}",
            $format->contentType(),
            match ($format) {
                DispatchResultFormat::Txt => DeliveredContent::copyAll($slots)."\n",
                DispatchResultFormat::Csv => self::csv($slots),
            },
        );
    }

    /**
     * CSV UTF-8 có BOM để Excel đọc đúng. Cột Trường nội dung là hợp các Trường nội dung của mọi
     * Sản phẩm trong phiếu theo tên hiển thị, giữ thứ tự xuất hiện; Sản phẩm không có trường đó
     * để trống.
     *
     * @param  list<DeliveredContent>  $slots
     */
    private static function csv(array $slots): string
    {
        $labels = array_values(array_unique(array_merge(...array_map(fn (DeliveredContent $slot): array => array_keys($slot->fields), $slots))));

        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Sản phẩm', ...$labels, 'Hạn sử dụng', 'Hạn bảo hành'], ',', '"', '');

            foreach ($slots as $slot) {
                fputcsv($handle, [
                    $slot->productName,
                    ...array_map(fn (string $label): string => $slot->fields[$label] ?? '', $labels),
                    $slot->expiresOn?->format(DeliveryTemplate::DATE_FORMAT) ?? '',
                    $slot->warrantyEndsOn->format(DeliveryTemplate::DATE_FORMAT),
                ], ',', '"', '');
            }

            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
