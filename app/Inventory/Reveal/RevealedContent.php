<?php

namespace App\Inventory\Reveal;

/**
 * Nội dung đầy đủ của một Slot, chỉ sống trong bộ nhớ của lần xem đã ghi Nhật ký xem mã.
 */
final readonly class RevealedContent
{
    /**
     * @param  array<string, string>  $fields  theo tên hiển thị Trường nội dung, đúng thứ tự trường
     */
    public function __construct(public array $fields) {}
}
