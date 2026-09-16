<?php

namespace App\Inventory\Reports;

use Carbon\CarbonImmutable;

/**
 * Bộ lọc báo cáo Lãi/lỗ: một khoảng ngày (Asia/Ho_Chi_Minh, tính cả hai đầu) và các bộ lọc cộng dồn
 * (và).
 *
 * Bộ lọc cũng quyết định phần nào của báo cáo còn nghĩa. Lãi gộp gắn với Dòng xuất nên lọc được theo
 * Kênh bán; còn Điều chỉnh (hàng lỗi, huỷ, hết hạn, Chi phí đổi hàng, bồi hoàn) phát sinh trong kho,
 * không đi qua Kênh bán nào, nên lọc Kênh bán thì Điều chỉnh và Lãi ròng kho bị ẩn thay vì hiện một
 * con số không có thật.
 */
final readonly class ProfitReportFilter
{
    /**
     * @param  CarbonImmutable  $from  ngày đầu khoảng, tính cả ngày này
     * @param  CarbonImmutable  $to  ngày cuối khoảng, tính cả ngày này
     * @param  list<int>  $productIds  rỗng thì mọi Sản phẩm
     * @param  ?int  $supplierId  chỉ tính hàng của Nhà cung cấp này, ở cả hai tầng
     * @param  ?int  $salesChannelId  chỉ tính Lãi gộp của hàng giao qua Kênh bán này
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
     * Bộ lọc báo cáo theo trạng thái bộ lọc của bảng Filament. Là chỗ duy nhất đọc trạng thái ấy, để
     * ba trang Lãi/lỗ không hiểu khác nhau về cùng một URL; trang nào không có bộ lọc nào thì khoá đó
     * vắng mặt và thành rỗng. Ngày hỏng thì lùi về khoảng mặc định thay vì làm gãy trang.
     *
     * @param  array<string, mixed>  $filters  giá trị `$tableFilters` của trang
     */
    public static function fromTableFilters(array $filters): self
    {
        $default = self::currentMonth();
        $day = fn (mixed $value, CarbonImmutable $fallback): CarbonImmutable => is_string($value) && $value !== ''
            ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value)->startOfDay(), $fallback, report: false)
            : $fallback;
        $id = fn (string $filter): ?int => is_numeric($value = $filters[$filter]['value'] ?? null) ? (int) $value : null;

        return new self(
            from: $day($filters['range']['from'] ?? null, $default->from),
            to: $day($filters['range']['to'] ?? null, $default->to),
            productIds: array_values(array_map(intval(...), array_filter((array) ($filters['products']['values'] ?? []), is_numeric(...)))),
            supplierId: $id('supplier'),
            salesChannelId: $id('channel'),
        );
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
     * Có hiện Điều chỉnh và Lãi ròng kho không: các khoản này phát sinh trong kho (hàng Lỗi, Huỷ hàng,
     * hết hạn, Chi phí đổi hàng, bồi hoàn) và không gắn với Kênh bán nào.
     */
    public function showsAdjustments(): bool
    {
        return $this->salesChannelId === null;
    }
}
