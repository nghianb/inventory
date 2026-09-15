<?php

namespace App\Inventory\Dispatch;

use Carbon\CarbonImmutable;

/**
 * Mẫu giao hàng của Sản phẩm: văn bản cố định kèm biến dạng `{{ten_bien}}`. Biến là định danh
 * Trường nội dung hoặc một trong các biến có sẵn {@see self::BUILT_IN}.
 */
final class DeliveryTemplate
{
    public const PRODUCT = 'san_pham';

    public const EXTERNAL_REF = 'ma_don';

    public const EXPIRES_ON = 'han_su_dung';

    public const WARRANTY_ENDS_ON = 'han_bao_hanh';

    public const BUILT_IN = [self::PRODUCT, self::EXTERNAL_REF, self::EXPIRES_ON, self::WARRANTY_ENDS_ON];

    public const DATE_FORMAT = 'd/m/Y';

    private const VARIABLE = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /**
     * Mẫu giữ nguyên văn; null khi chỉ có khoảng trắng (dùng mẫu mặc định).
     */
    public static function normalize(?string $template): ?string
    {
        return trim((string) $template) === '' ? null : $template;
    }

    /**
     * Tin nhắn gửi khách cho một Slot. Sản phẩm chưa có mẫu thì mỗi Trường nội dung một dòng
     * "Tên trường: giá trị".
     *
     * @param  array<string, string>  $values  giá trị theo định danh Trường nội dung
     * @param  array<string, string>  $fields  giá trị theo tên hiển thị, đúng thứ tự trường
     */
    public static function render(?string $template, array $values, array $fields, string $productName, string $externalRef, ?CarbonImmutable $expiresOn, CarbonImmutable $warrantyEndsOn): string
    {
        if ($template === null) {
            return implode("\n", array_map(fn (string $label, string $value): string => "{$label}: {$value}", array_keys($fields), $fields));
        }

        $variables = [
            ...$values,
            self::PRODUCT => $productName,
            self::EXTERNAL_REF => $externalRef,
            self::EXPIRES_ON => $expiresOn?->format(self::DATE_FORMAT) ?? 'Không thời hạn',
            self::WARRANTY_ENDS_ON => $warrantyEndsOn->format(self::DATE_FORMAT),
        ];

        return (string) preg_replace_callback(self::VARIABLE, fn (array $match): string => $variables[$match[1]] ?? '', $template);
    }

    /**
     * Biến trong mẫu không phải Trường nội dung hay biến có sẵn, theo thứ tự xuất hiện.
     *
     * @param  list<string>  $fieldKeys
     * @return list<string>
     */
    public static function unknownVariables(string $template, array $fieldKeys): array
    {
        preg_match_all(self::VARIABLE, $template, $matches);

        return array_values(array_unique(array_diff($matches[1], [...self::BUILT_IN, ...$fieldKeys])));
    }
}
