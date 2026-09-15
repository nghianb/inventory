<?php

namespace App\Inventory\Encryption;

use InvalidArgumentException;
use Normalizer;
use SensitiveParameter;

/**
 * Cách chuẩn hoá chuỗi trước khi tính hash Khoá chống trùng. Luôn bỏ ký tự vô hình
 * (zero-width, soft hyphen), NFKC và trim; hai tuỳ chọn còn lại lấy từ cấu hình Sản phẩm.
 */
final readonly class Normalization
{
    private const INVISIBLE = '/[\x{00AD}\x{180E}\x{200B}-\x{200D}\x{2060}-\x{2064}\x{FEFF}]/u';

    public function __construct(
        public bool $caseInsensitive = false,
        public bool $stripSeparators = false,
    ) {}

    /**
     * @throws InvalidArgumentException chuỗi không phải UTF-8 hợp lệ
     */
    public function apply(#[SensitiveParameter] string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        if ($normalized === false) {
            throw new InvalidArgumentException('Chuỗi không phải UTF-8 hợp lệ.');
        }

        $normalized = (string) preg_replace(self::INVISIBLE, '', $normalized);
        $normalized = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $normalized);

        if ($this->caseInsensitive) {
            $normalized = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
        }

        if ($this->stripSeparators) {
            $normalized = (string) preg_replace('/[\s\p{Z}\p{Pd}]+/u', '', $normalized);
        }

        return $normalized;
    }
}
