<?php

namespace App\Inventory\Reports;

use Carbon\CarbonImmutable;

/**
 * Bộ lọc báo cáo Nhập/xuất: một khoảng ngày (Asia/Ho_Chi_Minh, tính cả hai đầu) và các bộ lọc cộng
 * dồn (và).
 *
 * Bộ lọc cũng quyết định cột nào hiện, vì mỗi bộ lọc chỉ thu hẹp được phần luồng hàng mà nó mô tả
 * được: Nhập, Huỷ hàng và Chuyển Tồn lỗi không đi qua Kênh bán nào, còn Giá bán là của cả Dòng xuất nên
 * không chia được cho từng Nhà cung cấp.
 */
final readonly class MovementReportFilter
{
    /**
     * @param  CarbonImmutable  $from  ngày đầu khoảng, tính cả ngày này
     * @param  CarbonImmutable  $to  ngày cuối khoảng, tính cả ngày này
     * @param  list<int>  $productIds  rỗng thì mọi Sản phẩm
     * @param  ?int  $supplierId  chỉ đếm hàng của Nhà cung cấp này; chỉ Quản trị và Nhập kho
     * @param  ?int  $salesChannelId  chỉ đếm hàng giao qua Kênh bán này
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $productIds = [],
        public ?int $supplierId = null,
        public ?int $salesChannelId = null,
    ) {}

    /**
     * Khoảng mặc định khi mở báo cáo: từ đầu tháng tới hôm nay.
     */
    public static function currentMonth(): self
    {
        $today = CarbonImmutable::today();

        return new self($today->startOfMonth(), $today);
    }

    /**
     * Đầu khoảng: 00:00 của ngày `from`.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->from->startOfDay();
    }

    /**
     * Hết khoảng, không tính mốc này: 00:00 của ngày sau `to`.
     */
    public function endsBefore(): CarbonImmutable
    {
        return $this->to->startOfDay()->addDay();
    }

    /**
     * Có hiện Nhập, Huỷ hàng và Chuyển Tồn lỗi không: các biến động này không gắn với Kênh bán nào, nên
     * lọc Kênh bán thì con số của chúng không còn nghĩa.
     */
    public function showsStockFlow(): bool
    {
        return $this->salesChannelId === null;
    }

    /**
     * Có hiện Giá bán không: Giá bán là tổng tiền của cả Dòng xuất, mà một Dòng xuất có thể gồm Slot
     * của nhiều Nhà cung cấp, nên khi lọc Nhà cung cấp thì không chia được phần nào của ai.
     */
    public function showsSalePrice(): bool
    {
        return $this->supplierId === null;
    }
}
