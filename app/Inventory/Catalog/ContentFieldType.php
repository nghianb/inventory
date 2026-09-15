<?php

namespace App\Inventory\Catalog;

/**
 * Kiểu dựng sẵn của Trường nội dung.
 */
enum ContentFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Date = 'date';
    case Number = 'number';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Văn bản',
            self::Email => 'Email',
            self::Date => 'Ngày',
            self::Number => 'Số',
        };
    }

    /**
     * Giá trị (đã trim) có đúng kiểu không. Ngày viết YYYY-MM-DD hoặc DD/MM/YYYY.
     */
    public function accepts(string $value): bool
    {
        return match ($this) {
            self::Text => true,
            self::Email => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            self::Date => self::isDate($value, 'Y-m-d') || self::isDate($value, 'd/m/Y'),
            self::Number => preg_match('/^-?\d+(?:[.,]\d+)?$/', $value) === 1,
        };
    }

    /**
     * Mô tả giá trị mong đợi, dùng trong lý do lỗi định dạng.
     */
    public function expectation(): string
    {
        return match ($this) {
            self::Text => 'văn bản',
            self::Email => 'email hợp lệ',
            self::Date => 'ngày hợp lệ (YYYY-MM-DD hoặc DD/MM/YYYY)',
            self::Number => 'số',
        };
    }

    private static function isDate(string $value, string $format): bool
    {
        $date = \DateTimeImmutable::createFromFormat("!{$format}", $value);

        return $date !== false && $date->format($format) === $value;
    }
}
