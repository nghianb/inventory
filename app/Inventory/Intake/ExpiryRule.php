<?php

namespace App\Inventory\Intake;

use Carbon\CarbonImmutable;

/**
 * Hạn sử dụng khai ở Dòng nhập: một ngày cụ thể hoặc N ngày kể từ ngày nhập.
 */
final readonly class ExpiryRule
{
    private function __construct(
        public ?CarbonImmutable $date,
        public ?int $days,
    ) {}

    public static function on(CarbonImmutable $date): self
    {
        return new self($date->startOfDay(), null);
    }

    public static function afterDays(int $days): self
    {
        return new self(null, $days);
    }

    public function resolve(CarbonImmutable $receivedOn): CarbonImmutable
    {
        return $this->date ?? $receivedOn->startOfDay()->addDays((int) $this->days);
    }
}
