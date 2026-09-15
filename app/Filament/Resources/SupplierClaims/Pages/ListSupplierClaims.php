<?php

namespace App\Filament\Resources\SupplierClaims\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Resources\SupplierClaims\Widgets\UnclaimedDefectiveUnits;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Danh sách Khiếu nại nhà cung cấp, kèm bảng Đơn vị hàng Lỗi chưa khiếu nại để tạo khiếu nại từ đó.
 */
class ListSupplierClaims extends ListRecords
{
    protected static string $resource = SupplierClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tạo Khiếu nại'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UnclaimedDefectiveUnits::class,
        ];
    }
}
