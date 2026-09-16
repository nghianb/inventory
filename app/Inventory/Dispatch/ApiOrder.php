<?php

namespace App\Inventory\Dispatch;

/**
 * Một đơn website gửi vào kho qua API. Mã đơn ngoài là khoá idempotency: gửi lại cùng mã với
 * Dòng xuất giống hệt thì nhận lại đúng Phiếu xuất cũ.
 */
final readonly class ApiOrder
{
    /**
     * @param  list<ApiOrderLine>  $lines
     */
    public function __construct(
        public ?string $externalRef,
        public array $lines,
        public ?string $customer = null,
        public ?string $note = null,
    ) {}

    /**
     * Mã đơn ngoài đã bỏ khoảng trắng hai đầu; null khi để trống.
     */
    public function externalRef(): ?string
    {
        $ref = trim((string) $this->externalRef);

        return $ref === '' ? null : $ref;
    }
}
