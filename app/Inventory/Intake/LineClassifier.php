<?php

namespace App\Inventory\Intake;

use App\Inventory\Catalog\ContentPattern;
use App\Inventory\Catalog\StockForm;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\ContentField;
use App\Models\Product;
use App\Models\StockUnit;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Phân loại từng dòng của một Dòng nhập theo khai báo hiện tại của Loại sản phẩm: hợp lệ, nhập
 * lại Tài khoản hợp lệ, lỗi định dạng, trùng trong file (dòng xuất hiện sau), trùng trong
 * kho. Gộp giá trị theo tầng Dòng nhập → cột file. Không ghi gì.
 */
class LineClassifier
{
    public const MAX_SLOTS = 1_000;

    private const LOOKUP_CHUNK = 1_000;

    public function __construct(private ContentCrypto $crypto) {}

    /**
     * @return list<ClassifiedLine> theo thứ tự dòng
     */
    public function classify(Product $product, ParsedSource $source, LineDefaults $defaults): array
    {
        $fields = $product->contentFields->values()->all();
        $dedupeKey = $product->dedupeKeyField()->key;
        $normalization = $product->normalization();
        $form = $product->form();

        $lines = [];
        $firstSeen = [];

        foreach ($source->rows as $row) {
            $number = $row->lineNumber;
            $values = $row->error ?? self::values($fields, $row->cells);

            if (is_string($values)) {
                $lines[] = new ClassifiedLine($number, LineClass::Invalid, $values);

                continue;
            }

            $overrides = self::overrides($form, $row->overrides, $defaults);

            if (is_string($overrides)) {
                $lines[] = new ClassifiedLine($number, LineClass::Invalid, $overrides);

                continue;
            }

            if ($normalization->apply($values[$dedupeKey]) === '') {
                $lines[] = new ClassifiedLine($number, LineClass::Invalid, 'Khoá chống trùng rỗng sau khi chuẩn hoá.');

                continue;
            }

            $hash = $this->crypto->dedupeHash($values[$dedupeKey], $normalization);

            if (isset($firstSeen[$hash])) {
                $lines[] = new ClassifiedLine($number, LineClass::FileDuplicate, "Trùng Khoá chống trùng với dòng {$firstSeen[$hash]}.");

                continue;
            }

            $firstSeen[$hash] = $number;
            [$slots, $expiresOn, $unitCost] = $overrides;
            $lines[] = new ClassifiedLine($number, LineClass::Valid, null, $values, $hash, $slots, $expiresOn, $unitCost);
        }

        $holders = self::keyHolders($form, array_keys($firstSeen));

        return array_map(function (ClassifiedLine $line) use ($holders): ClassifiedLine {
            if ($line->class !== LineClass::Valid || ! array_key_exists((string) $line->dedupeHash, $holders)) {
                return $line;
            }

            $renewable = $holders[(string) $line->dedupeHash];

            return $renewable === null ? $line->asStockDuplicate() : $line->asRenewalOf($renewable);
        }, $lines);
    }

    /**
     * Đơn vị hàng đang chiếm Khoá chống trùng. Mã dùng một lần là duy nhất toàn kho mãi mãi,
     * bất kể Sản phẩm hay trạng thái, trừ khi đã Huỷ nhập. Tài khoản chỉ trùng với Đơn vị hàng
     * còn chiếm khoá; nếu cái đó đã Huỷ hàng hoặc quá Hạn sử dụng thì được nhập lại.
     *
     * @param  list<string>  $hashes
     * @return array<string, ?int> hash → id Đơn vị hàng nhập lại được, null nếu khoá đang bị chiếm
     */
    private static function keyHolders(StockForm $kind, array $hashes): array
    {
        $today = CarbonImmutable::today();
        $holders = [];

        foreach (array_chunk($hashes, self::LOOKUP_CHUNK) as $chunk) {
            $units = StockUnit::query()
                ->where('kind', $kind)
                ->whereIn('dedupe_hash', $chunk)
                ->where('holds_dedupe_key', true)
                ->get(['id', 'dedupe_hash', 'status', 'expires_on']);

            foreach ($units as $unit) {
                $renewable = $kind === StockForm::Account
                    && ($unit->status === StockUnitStatus::Voided || $unit->expires_on?->lt($today) === true);

                $holders[$unit->dedupe_hash] = $renewable ? $unit->id : null;
            }
        }

        return $holders;
    }

    /**
     * @param  list<ContentField>  $fields
     * @param  array<string, string>  $cells
     * @return array<string, string>|string giá trị đã trim theo định danh trường, hoặc lý do lỗi định dạng
     */
    private static function values(array $fields, array $cells): array|string
    {
        $values = [];

        foreach ($fields as $field) {
            $value = self::trim($cells[$field->key] ?? '');

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
     * Cột file ghi đè giá trị Dòng nhập; ô trống thì giữ giá trị Dòng nhập.
     *
     * @param  array<string, string>  $overrides
     * @return array{int, ?string, int}|string [số slot, Hạn sử dụng, Giá vốn], hoặc lý do lỗi định dạng
     */
    private static function overrides(StockForm $type, array $overrides, LineDefaults $defaults): array|string
    {
        $slots = $defaults->slots;
        $raw = self::trim($overrides[SourceReader::COLUMN_SLOTS] ?? '');

        if ($raw !== '') {
            if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > self::MAX_SLOTS) {
                return sprintf('Cột slot phải là số nguyên từ 1 đến %s.', number_format(self::MAX_SLOTS, 0, ',', '.'));
            }

            if ($type === StockForm::OneTimeCode && (int) $raw !== 1) {
                return 'Mã dùng một lần luôn có đúng 1 slot.';
            }

            $slots = (int) $raw;
        }

        $expiresOn = $defaults->expiresOn;
        $raw = self::trim($overrides[SourceReader::COLUMN_EXPIRY] ?? '');

        if ($raw !== '') {
            $expiresOn = self::expiry($raw, $defaults->receivedOn);

            if ($expiresOn === null) {
                return 'Cột han_su_dung phải là ngày (YYYY-MM-DD hoặc DD/MM/YYYY) hoặc số ngày kể từ ngày nhập.';
            }
        }

        $unitCost = $defaults->unitCost;
        $raw = (string) preg_replace('/[\s.,₫]/u', '', self::trim($overrides[SourceReader::COLUMN_COST] ?? ''));

        if ($raw !== '') {
            if (preg_match('/^\d{1,15}$/', $raw) !== 1) {
                return 'Cột gia_von phải là số tiền VND nguyên, không âm.';
            }

            $unitCost = (int) $raw;
        }

        return [$slots, $expiresOn?->toDateString(), $unitCost];
    }

    /**
     * Số ngày tối đa 4 chữ số: ô ngày XLSX không định dạng ngày đọc ra số serial (≈ 46.000) thì
     * báo lỗi định dạng thay vì hiểu nhầm thành hàng trăm năm.
     */
    private static function expiry(string $raw, CarbonImmutable $receivedOn): ?CarbonImmutable
    {
        if (preg_match('/^\d{1,4}$/', $raw) === 1) {
            return $receivedOn->startOfDay()->addDays((int) $raw);
        }

        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat("!{$format}", $raw);

            if ($date !== false && $date->format($format) === $raw) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    private static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value);
    }
}
