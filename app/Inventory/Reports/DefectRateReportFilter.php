<?php

namespace App\Inventory\Reports;

use Carbon\CarbonImmutable;

/**
 * Bộ lọc báo cáo Tỉ lệ lỗi theo Nhà cung cấp: một khoảng ngày (Asia/Ho_Chi_Minh, tính cả hai đầu) và
 * các bộ lọc cộng dồn (và).
 *
 * Khoảng ngày là khoảng **nhập** hàng, không phải khoảng phát hiện lỗi: Tỉ lệ lỗi tính theo lứa nhập,
 * nên tử số và mẫu số luôn nằm trên cùng một tập Đơn vị hàng.
 */
final readonly class DefectRateReportFilter
{
    /**
     * @param  CarbonImmutable  $from  ngày đầu khoảng, tính cả ngày này
     * @param  CarbonImmutable  $to  ngày cuối khoảng, tính cả ngày này
     * @param  list<int>  $supplierIds  rỗng thì mọi Nhà cung cấp
     * @param  list<int>  $productIds  rỗng thì mọi Sản phẩm
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $supplierIds = [],
        public array $productIds = [],
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
}
