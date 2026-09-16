<?php

namespace App\Inventory\Catalog;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Kênh bán loại API tham chiếu tới Mã sản phẩm không có trong kho. Giữ nguyên các mã
 * sai để người gọi nói rõ mã nào hỏng, thay vì chỉ báo "có mã sai".
 */
class UnknownProductCode extends RuntimeException
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(public readonly array $codes)
    {
        parent::__construct(implode(' ', self::messages($codes)));
    }

    public static function message(string $code): string
    {
        return "Không có Sản phẩm với Mã sản phẩm \"{$code}\".";
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function messages(array $codes): array
    {
        return array_map(self::message(...), $codes);
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return self::messages($this->codes);
    }
}
