<?php

namespace App\Inventory\Intake;

use App\Inventory\Catalog\ContentPattern;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Encryption\ContentCrypto;
use App\Models\ContentField;
use App\Models\Product;
use App\Models\StockUnit;
use SensitiveParameter;

/**
 * Phân loại từng dòng dán theo cấu hình hiện tại của Sản phẩm: hợp lệ, lỗi định dạng,
 * trùng trong file (dòng xuất hiện sau), trùng trong kho. Không ghi gì.
 */
class LineClassifier
{
    private const LOOKUP_CHUNK = 1_000;

    public function __construct(private ContentCrypto $crypto) {}

    /**
     * @return list<ClassifiedLine> theo thứ tự dòng, bỏ qua dòng trống
     */
    public function classify(Product $product, #[SensitiveParameter] string $content, string $separator): array
    {
        $fields = $product->contentFields->values()->all();
        $dedupeKey = $product->dedupeKeyField()->key;
        $normalization = $product->normalization();

        $lines = [];
        $firstSeen = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $number = $index + 1;
            $parsed = self::parse($fields, $raw, $separator);

            if (is_string($parsed)) {
                $lines[] = new ClassifiedLine($number, LineClass::Invalid, $parsed);

                continue;
            }

            if ($normalization->apply($parsed[$dedupeKey]) === '') {
                $lines[] = new ClassifiedLine($number, LineClass::Invalid, 'Khoá chống trùng rỗng sau khi chuẩn hoá.');

                continue;
            }

            $hash = $this->crypto->dedupeHash($parsed[$dedupeKey], $normalization);

            if (isset($firstSeen[$hash])) {
                $lines[] = new ClassifiedLine($number, LineClass::FileDuplicate, "Trùng Khoá chống trùng với dòng {$firstSeen[$hash]}.");

                continue;
            }

            $firstSeen[$hash] = $number;
            $lines[] = new ClassifiedLine($number, LineClass::Valid, values: $parsed, dedupeHash: $hash);
        }

        $taken = self::takenHashes(array_keys($firstSeen));

        return array_map(
            fn (ClassifiedLine $line): ClassifiedLine => $line->class === LineClass::Valid && isset($taken[$line->dedupeHash])
                ? $line->asStockDuplicate()
                : $line,
            $lines,
        );
    }

    /**
     * @param  list<ContentField>  $fields
     * @return array<string, string>|string giá trị theo định danh trường, hoặc lý do lỗi định dạng
     */
    private static function parse(array $fields, #[SensitiveParameter] string $raw, string $separator): array|string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return 'Dòng không phải văn bản UTF-8 hợp lệ.';
        }

        $columns = count($fields) === 1 ? [$raw] : explode($separator, $raw);

        if (count($columns) > count($fields)) {
            return sprintf('Dòng có %d cột, Sản phẩm chỉ có %d Trường nội dung.', count($columns), count($fields));
        }

        $values = [];

        foreach ($fields as $position => $field) {
            $value = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $columns[$position] ?? '');

            if ($value === '') {
                if ($field->required) {
                    return "Thiếu giá trị cho trường \"{$field->label}\".";
                }

                continue;
            }

            if (! $field->type->accepts($value)) {
                return "Trường \"{$field->label}\" không phải {$field->type->expectation()}.";
            }

            if ($field->pattern !== null && preg_match((string) ContentPattern::compile($field->pattern), $value) !== 1) {
                return "Trường \"{$field->label}\" không khớp định dạng khai báo.";
            }

            $values[$field->key] = $value;
        }

        return $values;
    }

    /**
     * Mã dùng một lần là duy nhất toàn kho mãi mãi, bất kể Sản phẩm hay trạng thái.
     *
     * @param  list<string>  $hashes
     * @return array<string, true>
     */
    private static function takenHashes(array $hashes): array
    {
        $taken = [];

        foreach (array_chunk($hashes, self::LOOKUP_CHUNK) as $chunk) {
            foreach (StockUnit::query()->where('kind', ProductType::OneTimeCode)->whereIn('dedupe_hash', $chunk)->pluck('dedupe_hash') as $hash) {
                $taken[$hash] = true;
            }
        }

        return $taken;
    }
}
