<?php

namespace App\Inventory\Encryption;

use RuntimeException;

/**
 * Khoá mã hoá trong môi trường không khớp dấu vân tay đã đăng ký trong DB. Tiến trình ghi
 * (nhập, xuất) phải từ chối chạy. Thông báo chỉ nêu loại khoá và phiên bản, không nêu giá trị.
 */
class KeyFingerprintMismatch extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Khoá mã hoá không khớp với DB, từ chối ghi: '.implode('; ', $problems).'.');
    }
}
