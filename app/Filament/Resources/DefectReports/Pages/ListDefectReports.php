<?php

namespace App\Filament\Resources\DefectReports\Pages;

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Inventory\Warranty\DefectReportStatus;
use App\Models\DefectReport;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Danh sách Báo lỗi; tab tồn đọng là Báo lỗi Chờ xác minh quá số giờ cấu hình.
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
            'overdue' => Tab::make("Chờ xác minh quá {$hours} giờ")
                ->badge(fn (): int => DefectReport::query()->overdue()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey(DefectReport::query()->overdue()->select('id'))),
        ];
    }
}
