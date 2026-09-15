<?php

namespace App\Inventory\Intake;

use App\Models\ContentField;
use App\Models\Product;
use DateTimeInterface;
use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use SensitiveParameter;
use Throwable;

/**
 * Tách nội dung một Dòng nhập (văn bản dán, CSV, XLSX) thành từng dòng dữ liệu theo
 * định danh Trường nội dung. Không kiểm tra giá trị, không ghi gì ra ngoài bộ nhớ trừ file
 * XLSX tạm (thư viện đọc XLSX cần file thật), xoá ngay sau khi đọc.
 */
class SourceReader
{
    public const COLUMN_SLOTS = 'slot';

    public const COLUMN_EXPIRY = 'han_su_dung';

    public const COLUMN_COST = 'gia_von';

    private const OVERRIDE_COLUMNS = [self::COLUMN_SLOTS, self::COLUMN_EXPIRY, self::COLUMN_COST];

    private const NOT_UTF8 = 'Dòng không phải văn bản UTF-8 hợp lệ.';

    /**
     * @throws InvalidBatch file không đọc được, thiếu dòng tiêu đề hoặc thiếu cột bắt buộc
     */
    public function read(Product $product, #[SensitiveParameter] string $content, IntakeSource $source, string $separator): ParsedSource
    {
        $fields = $product->contentFields->values()->all();

        return match ($source) {
            IntakeSource::Paste => new ParsedSource(self::pasted($fields, $content, $separator)),
            IntakeSource::Csv => self::table($fields, self::csvRows($content)),
            IntakeSource::Xlsx => self::table($fields, self::xlsxRows($content)),
        };
    }

    /**
     * @param  list<ContentField>  $fields
     * @return list<ParsedRow>
     */
    private static function pasted(array $fields, #[SensitiveParameter] string $content, string $separator): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $number = $index + 1;

            if (! mb_check_encoding($raw, 'UTF-8')) {
                $rows[] = new ParsedRow($number, error: self::NOT_UTF8);

                continue;
            }

            $columns = count($fields) === 1 ? [$raw] : explode($separator, $raw);

            if (count($columns) > count($fields)) {
                $rows[] = new ParsedRow($number, error: sprintf('Dòng có %d cột, Sản phẩm chỉ có %d Trường nội dung.', count($columns), count($fields)));

                continue;
            }

            $cells = [];

            foreach ($fields as $position => $field) {
                $cells[$field->key] = $columns[$position] ?? '';
            }

            $rows[] = new ParsedRow($number, $cells);
        }

        return $rows;
    }

    /**
     * Dòng không trống đầu tiên là dòng tiêu đề.
     *
     * @param  list<ContentField>  $fields
     * @param  iterable<int, array<int, string>>  $rows  số dòng → ô theo vị trí cột
     *
     * @throws InvalidBatch
     */
    private static function table(array $fields, iterable $rows): ParsedSource
    {
        $columns = null;
        $ignored = [];
        $parsed = [];

        foreach ($rows as $number => $cells) {
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            if ($columns === null) {
                [$columns, $ignored] = self::header($fields, $cells);

                continue;
            }

            if (array_filter($cells, fn (string $cell): bool => ! mb_check_encoding($cell, 'UTF-8')) !== []) {
                $parsed[] = new ParsedRow($number, error: self::NOT_UTF8);

                continue;
            }

            $values = [];
            $overrides = [];

            foreach ($columns as $index => [$kind, $name]) {
                if ($kind === 'field') {
                    $values[$name] = $cells[$index] ?? '';
                } else {
                    $overrides[$name] = $cells[$index] ?? '';
                }
            }

            $parsed[] = new ParsedRow($number, $values, $overrides);
        }

        if ($columns === null) {
            throw new InvalidBatch('File không có dòng tiêu đề.');
        }

        return new ParsedSource($parsed, $ignored);
    }

    /**
     * Cột khớp định danh hoặc tên hiển thị Trường nội dung (không phân biệt hoa thường), hoặc
     * tên cột ghi đè. Cột khác bị bỏ qua và báo lại.
     *
     * @param  list<ContentField>  $fields
     * @param  array<int, string>  $cells
     * @return array{array<int, array{string, string}>, list<string>}
     *
     * @throws InvalidBatch
     */
    private static function header(array $fields, array $cells): array
    {
        if (! mb_check_encoding(implode('', $cells), 'UTF-8')) {
            throw new InvalidBatch('Dòng tiêu đề không phải văn bản UTF-8 hợp lệ.');
        }

        $byName = [];

        foreach ($fields as $field) {
            $byName[self::columnName($field->label)] = $field->key;
            $byName[self::columnName($field->key)] = $field->key;
        }

        $columns = [];
        $ignored = [];
        $taken = [];

        foreach ($cells as $index => $title) {
            $name = self::columnName($title);

            if ($name === '') {
                continue;
            }

            $target = match (true) {
                isset($byName[$name]) => ['field', $byName[$name]],
                in_array($name, self::OVERRIDE_COLUMNS, true) => ['override', $name],
                default => null,
            };

            if ($target === null) {
                $ignored[] = trim($title);

                continue;
            }

            if (isset($taken[$target[1]])) {
                throw new InvalidBatch('File có hai cột cho "'.trim($title).'".');
            }

            $taken[$target[1]] = true;
            $columns[$index] = $target;
        }

        $missing = array_filter($fields, fn (ContentField $field): bool => $field->required && ! isset($taken[$field->key]));

        if ($missing !== []) {
            throw new InvalidBatch('File thiếu cột cho Trường nội dung bắt buộc: '.implode(', ', array_map(fn (ContentField $field): string => $field->label, $missing)).'.');
        }

        return [$columns, $ignored];
    }

    private static function columnName(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    /**
     * Ký tự phân tách đoán từ dòng đầu: dấu phẩy, chấm phẩy hoặc tab.
     *
     * @return Generator<int, array<int, string>>
     */
    private static function csvRows(#[SensitiveParameter] string $content): Generator
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $firstLine = preg_split('/\r\n|\n|\r/', $content, 2)[0] ?? '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        $delimiter = array_search(max($counts), $counts, true) ?: ',';

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new InvalidBatch('Không đọc được file CSV.');
        }

        try {
            fwrite($handle, $content);
            rewind($handle);
            $number = 0;

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;

                yield $number => array_map(fn (?string $cell): string => (string) $cell, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Chỉ đọc sheet đầu tiên.
     *
     * @return Generator<int, array<int, string>>
     */
    private static function xlsxRows(#[SensitiveParameter] string $content): Generator
    {
        $path = tempnam(sys_get_temp_dir(), 'intake-');

        if ($path === false) {
            throw new InvalidBatch('Không đọc được file XLSX.');
        }

        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        $opened = false;

        try {
            file_put_contents($path, $content);

            try {
                $reader->open($path);
                $opened = true;
                $sheets = $reader->getSheetIterator();
                $sheets->rewind();
                $rows = $sheets->current()->getRowIterator();
            } catch (Throwable) {
                throw new InvalidBatch('File XLSX không đọc được.');
            }

            $number = 0;

            foreach ($rows as $row) {
                $number++;

                yield $number => array_map(self::cellText(...), $row->toArray());
            }
        } finally {
            if ($opened) {
                $reader->close();
            }

            @unlink($path);
        }
    }

    private static function cellText(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
