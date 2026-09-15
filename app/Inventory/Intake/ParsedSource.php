<?php

namespace App\Inventory\Intake;

/**
 * Nội dung một Dòng nhập đã tách thành từng dòng dữ liệu (plaintext, chỉ sống trong bộ nhớ).
 */
final readonly class ParsedSource
{
    /**
     * @param  list<ParsedRow>  $rows
     * @param  list<string>  $ignoredColumns  tiêu đề cột file không khớp Trường nội dung nào
     */
    public function __construct(
        public array $rows,
        public array $ignoredColumns = [],
    ) {}
}
