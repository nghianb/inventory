<?php

namespace App\Inventory\Catalog;

/**
 * Màn xem trước của một lần sửa Loại sản phẩm: thay đổi nào áp xuống mọi Sản phẩm của Loại,
 * thay đổi nào bị từ chối, và Sản phẩm nào đang chặn.
 *
 * Sửa Loại chia làm hai hạng (ADR 0004): thêm trường tuỳ chọn và đổi tên hiển thị không đụng
 * tới nội dung đã mã hoá hay hash Khoá chống trùng nên luôn áp được; mọi thay đổi khác chạm
 * dữ liệu đã lưu, và khi Loại đã có Sản phẩm có hàng thì bị từ chối **trọn gói** — không áp
 * một phần, kể cả cho Sản phẩm chưa có hàng.
 */
final readonly class ProductTypeChange
{
    /**
     * @param  list<string>  $applied  thay đổi sẽ ghi, viết cho Quản trị đọc
     * @param  list<string>  $rejected  thay đổi chạm dữ liệu đã lưu, chỉ khác rỗng khi có Sản phẩm chặn
     * @param  list<string>  $blockingProducts  Mã sản phẩm của các Sản phẩm đã có hàng đang chặn
     * @param  list<string>  $affectedProducts  Mã sản phẩm của mọi Sản phẩm thuộc Loại
     */
    public function __construct(
        public array $applied,
        public array $rejected,
        public array $blockingProducts,
        public array $affectedProducts,
    ) {}

    public function isRejected(): bool
    {
        return $this->rejected !== [];
    }

    public function isEmpty(): bool
    {
        return $this->applied === [] && $this->rejected === [];
    }

    /**
     * Lý do từ chối, nêu đích danh Sản phẩm đang chặn. Viết cho Quản trị đọc, không phải log.
     */
    public function rejectionMessage(): string
    {
        return sprintf(
            'Loại sản phẩm đã có Sản phẩm có hàng nên không ghi gì cả. Thay đổi bị từ chối: %s Sản phẩm đang chặn: %s.',
            implode(' ', $this->rejected),
            implode(', ', $this->blockingProducts),
        );
    }
}
