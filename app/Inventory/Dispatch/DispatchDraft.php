<?php

namespace App\Inventory\Dispatch;

use App\Models\SalesChannel;

/**
 * Nội dung form tạo Phiếu xuất. Có thể chưa đầy đủ: {@see ManualDispatch::check()} báo lỗi.
 */
final readonly class DispatchDraft
{
    /**
     * @param  ?string  $externalRef  để trống thì tự sinh nếu kênh không bắt buộc
     * @param  list<DispatchLineDraft>  $lines
     * @param  ?string  $customer  thông tin khách dạng văn bản tự do
     */
    public function __construct(
        public ?SalesChannel $channel,
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
