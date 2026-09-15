<?php

namespace App\Inventory\Catalog;

/**
 * Regex tuỳ chọn của Trường nội dung. Quản trị chỉ viết phần thân (không delimiter);
 * giá trị phải khớp toàn bộ, không chỉ chứa một đoạn khớp.
 */
final class ContentPattern
{
    /**
     * Regex PCRE đầy đủ, hoặc null nếu phần thân không biên dịch được.
     */
    public static function compile(string $pattern): ?string
    {
        $regex = '~^(?:'.str_replace('~', '\~', $pattern).')$~u';

        return @preg_match($regex, '') === false ? null : $regex;
    }
}
