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
        return self::map($fields, $plainValues, fn (ContentField $field): string => $field->label);
    }

    /**
     * Như {@see of()} nhưng theo định danh Trường nội dung, cho nơi cần khoá ổn định.
     *
     * @param  Collection<int, ContentField>  $fields
     * @param  array<string, string>  $plainValues
     * @return array<string, string>
     */
    public static function byKey(Collection $fields, array $plainValues): array
    {
        return self::map($fields, $plainValues, fn (ContentField $field): string => $field->key);
    }

    /**
     * @param  Collection<int, ContentField>  $fields
     * @param  array<string, string>  $plainValues
     * @param  callable(ContentField): string  $keyBy
     * @return array<string, string>
     */
    private static function map(Collection $fields, array $plainValues, callable $keyBy): array
    {
        return $fields->mapWithKeys(fn (ContentField $field): array => [
            $keyBy($field) => $field->sensitive ? self::MASK : ($plainValues[$field->key] ?? ''),
        ])->all();
    }
}
