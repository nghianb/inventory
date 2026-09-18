<?php

namespace App\Filament\Resources\DefectReports\Pages;

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Inventory\Warranty\DefectReportStatus;
use App\Models\DefectReport;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Danh sách Báo lỗi; tab tồn đọng là Báo lỗi Chờ xác minh quá số giờ cấu hình, tab Chờ đổi là Báo lỗi
 * Xác nhận chưa Đổi hàng hay Không đổi, tab Chờ Quản trị duyệt là Đổi hàng từ lần thứ 3 đã được yêu
 * cầu duyệt.
 */
class ListDefectReports extends ListRecords
{
    protected static string $resource = DefectReportResource::class;

    public function getTabs(): array
    {
        $hours = (int) config('inventory.defect.backlog_hours');

        return [
            'all' => Tab::make('Tất cả'),
            'pending' => Tab::make('Chờ xác minh')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DefectReportStatus::Pending)),
            // Gọi thẳng scope, không lọc qua truy vấn con: `whereKey($subquery)` sinh ra `id = (...)`,
            // và Postgres bỏ ngay khi tab có từ hai dòng. `$query` không khai kiểu vì Larastan không
            // thấy scope của model trên Builder chung.
            'overdue' => Tab::make("Chờ xác minh quá {$hours} giờ")
                ->badge(fn (): int => DefectReport::query()->overdue()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->overdue()),
            'awaiting' => Tab::make('Chờ đổi')
                ->badge(fn (): int => DefectReport::query()->awaitingReplacement()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->awaitingReplacement()),
            'approval' => Tab::make('Chờ Quản trị duyệt')
                ->badge(fn (): int => DefectReport::query()->awaitingApproval()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->awaitingApproval()),
        ];
    }
}
