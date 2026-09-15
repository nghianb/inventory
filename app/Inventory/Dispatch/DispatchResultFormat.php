<?php

namespace App\Inventory\Dispatch;

/**
 * Định dạng file tải từ màn kết quả xuất kho.
 */
enum DispatchResultFormat: string
{
    /** Tin nhắn theo Mẫu giao hàng của từng Slot, có dòng phân cách. */
    case Txt = 'txt';

    /** Mỗi Slot một dòng, mỗi Trường nội dung một cột. */
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Txt => 'TXT',
            self::Csv => 'CSV',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Txt => 'text/plain; charset=UTF-8',
            self::Csv => 'text/csv; charset=UTF-8',
        };
    }
}
