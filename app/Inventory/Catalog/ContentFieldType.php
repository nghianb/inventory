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
}
