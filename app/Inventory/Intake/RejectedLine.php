<?php

namespace App\Inventory\Intake;

/**
 * Dòng bị bỏ khi nhập, kèm lý do. Không chứa nội dung dòng.
 */
final readonly class RejectedLine
{
    public function __construct(
        public int $lineNumber,
        public LineClass $class,
        public string $reason,
    ) {}
}
