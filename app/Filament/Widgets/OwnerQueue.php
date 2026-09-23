<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DispatchFreezePage;
use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\QueueStat;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Models\DefectReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Việc chỉ Quản trị làm được, trên trang Tổng quan.
 *
 * Tạm dừng xuất kho không phải hàng đợi đếm được, nhưng đúng là việc chưa xong: kho luôn ở trạng thái
 * này sau khi khôi phục từ backup, cho tới khi Quản trị đối chiếu xong các đơn phát sinh sau mốc khôi
 * phục. Trước widget này nó không hiện ở đâu ngoài trang của chính nó, nên kho đang dừng mà Quản trị
 * không mở trang ấy thì không ai thấy. Bán hàng không thấy ô này: lúc kho dừng thì thao tác giao hàng
 * đã tự báo lỗi ngay tại chỗ.
 */
class OwnerQueue extends StatsOverviewWidget
{
    protected static ?int $sort = 40;

    protected ?string $heading = 'Quản trị';

    public static function canView(): bool
    {
        // allows() không kèm Vai trò nào: chỉ Quản trị. Cùng cách ProfitReport khoá báo cáo tiền.
        return app(RoleGate::class)->allows(InventoryAction::actor());
    }

    public function mount(): void
    {
        abort_unless(self::canView(), 403);
    }

    protected function getStats(): array
    {
        $freeze = app(DispatchFreeze::class)->state();

        return [
            QueueStat::make(
                'Đổi hàng chờ duyệt',
                DefectReport::query()->awaitingApproval()->count(),
                DefectReportResource::getUrl('index', ['tab' => 'approval']),
                description: 'Từ lần đổi thứ 3 trong cùng chuỗi',
            ),
            Stat::make('Tạm dừng xuất kho', $freeze->isFrozen() ? 'Đang tạm dừng' : 'Đang chạy')
                ->description($freeze->isFrozen() ? $freeze->reason : 'Kênh bán giữ và giao hàng bình thường')
                ->color($freeze->isFrozen() ? 'danger' : 'gray')
                ->url(DispatchFreezePage::getUrl()),
        ];
    }
}
