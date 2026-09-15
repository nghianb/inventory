<?php

namespace App\Inventory\Claims;

use Carbon\CarbonImmutable;

/**
 * Kết quả nhân viên ghi cho một Đơn vị hàng khi giải quyết khiếu nại. Số tiền và ngày chỉ dùng cho
 * Bồi hoàn tiền.
 */
final readonly class ClaimOutcomeDraft
{
    /**
     * @param  ?int  $refundAmount  VND
     */
    public function __construct(
        public ClaimOutcome $outcome,
        public ?int $refundAmount = null,
        public ?CarbonImmutable $refundedOn = null,
        public ?string $note = null,
    ) {}

    public static function refund(int $amount, CarbonImmutable $on, ?string $note = null): self
    {
        return new self(ClaimOutcome::Refund, $amount, $on, $note);
    }

    public static function replacementGoods(?string $note = null): self
    {
        return new self(ClaimOutcome::ReplacementGoods, note: $note);
    }

    public static function rejected(?string $note = null): self
    {
        return new self(ClaimOutcome::Rejected, note: $note);
    }
}
