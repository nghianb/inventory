<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\QueueStat;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Warranty\DefectReportStatus;
use App\Models\DefectReport;
use App\Models\Dispatch;
use Filament\Widgets\StatsOverviewWidget;

/**
 * Việc Xuất hàng còn dang dở, trên trang Tổng quan. Tách khỏi {@see IntakeQueue} theo *công việc* chứ
 * không theo Vai trò: một widget mà số ô đổi theo người xem thì bố cục co giãn, nên mỗi công việc một
 * widget với canView riêng và số ô cố định.
 *
 * Ba ô Báo lỗi dẫn sang tab có sẵn của danh sách Báo lỗi, không sinh bộ lọc song song nói cùng một thứ.
 */
class OutboundQueue extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Xuất hàng';

    public static function canView(): bool
    {
        return InventoryAction::actor()->can('viewAny', Dispatch::class);
    }

    public function mount(): void
    {
        abort_unless(self::canView(), 403);
    }

    protected function getStats(): array
    {
        return [
            QueueStat::make(
                'Phiếu xuất Đang giữ',
                Dispatch::query()->where('status', DispatchStatus::Holding)->count(),
                self::dispatches(DispatchStatus::Holding),
                description: 'Chờ giao, hạn giữ đang đếm ngược',
                color: 'gray',
            ),
            QueueStat::make(
                'Phiếu xuất Hết hạn giữ',
                Dispatch::query()->where('status', DispatchStatus::HoldExpired)->count(),
                self::dispatches(DispatchStatus::HoldExpired),
                description: 'Đã nhả Slot, giao lại được nếu còn hàng',
            ),
            // Mỗi Báo lỗi Chờ xác minh còn tạm ngừng bán các Slot cùng Đơn vị hàng, nên nó vừa là việc
            // chưa làm vừa là hàng đang bị khoá: ô nặng nhất của widget này.
            QueueStat::make(
                'Báo lỗi Chờ xác minh',
                DefectReport::query()->where('status', DefectReportStatus::Pending)->count(),
                DefectReportResource::getUrl('index', ['tab' => 'pending']),
                description: 'Đang tạm ngừng bán hàng cùng Đơn vị hàng',
                color: 'danger',
            ),
            QueueStat::make(
                'Báo lỗi Chờ đổi',
                DefectReport::query()->awaitingReplacement()->count(),
                DefectReportResource::getUrl('index', ['tab' => 'awaiting']),
                description: 'Đã xác nhận, khách đang đợi hàng',
            ),
        ];
    }

    private static function dispatches(DispatchStatus $status): string
    {
        return DispatchResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);
    }
}
