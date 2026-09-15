<?php

namespace App\Inventory\Warranty;

/**
 * Lý do một lần giao không Báo lỗi được. `overridable`: ngoài bảo hành, Quản trị vượt được kèm lý do.
 */
final readonly class ReportProblem
{
    public function __construct(
        public string $message,
        public bool $overridable,
    ) {}
}
