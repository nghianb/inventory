<?php

namespace App\Inventory\Stock;

use App\Models\ContentField;
use Illuminate\Support\Collection;

/**
 * Nội dung Đơn vị hàng ở dạng che: trường nhạy cảm luôn là cùng một chuỗi che, không
 * lộ ký tự hay độ dài nào; trường không nhạy cảm hiện nguyên giá trị.
 */
final class MaskedContent
{
    public const MASK = '••••••';

    /**
     * @param  Collection<int, ContentField>  $fields
     * @param  array<string, string>  $plainValues  giá trị các trường không nhạy cảm, theo định danh
     * @return array<string, string> theo tên hiển thị, đúng thứ tự trường
     */
    public static function of(Collection $fields, array $plainValues): array
    {
        return $fields->mapWithKeys(fn (ContentField $field): array => [
            $field->label => $field->sensitive ? self::MASK : ($plainValues[$field->key] ?? ''),
        ])->all();
    }
}
