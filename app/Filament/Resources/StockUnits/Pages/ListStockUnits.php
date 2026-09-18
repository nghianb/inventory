<?php

namespace App\Filament\Resources\StockUnits\Pages;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Models\StockUnit;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Danh sách Đơn vị hàng. Tab chia theo việc cần làm chứ không theo Trạng thái — lọc theo Trạng thái
 * đã có sẵn bộ lọc: Bán được là hàng giao ngay được, ba tab sau là hàng còn trong kho mà đang bị
 * khoá vì Báo lỗi Chờ xác minh, vì Đơn vị hàng Lỗi, hoặc vì đã quá Hạn sử dụng. Ba tab đó không rời
 * nhau: một đơn vị quá hạn đang có Báo lỗi Chờ xác minh nằm ở cả hai. Mặc định là Tất cả vì trang
 * này còn dùng để tra cứu một Đơn vị hàng cụ thể, kể cả hàng Lỗi và Đã huỷ.
 */
class ListStockUnits extends ListRecords
{
    protected static string $resource = StockUnitResource::class;

    public function getTabs(): array
    {
        // Vị từ nằm ở scope trên StockUnit để badge và bộ lọc của cùng một tab không thể lệch nghĩa
        // nhau. `$query` không khai kiểu vì Larastan không thấy scope của model trên Builder chung;
        // năm tab đều có test đi qua nên gõ sai tên scope là vỡ ngay.
        return [
            'all' => Tab::make('Tất cả'),
            // Không badge: số lớn, không ai hành động theo, mà lại là truy vấn đắt nhất mỗi lần tải trang.
            'sellable' => Tab::make('Bán được')
                ->modifyQueryUsing(fn ($query) => $query->sellable()),
            'paused' => Tab::make('Tạm ngừng vì Báo lỗi')
                ->badge(fn (): int => StockUnit::query()->pausedByDefect()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->pausedByDefect()),
            // Nhãn nói "Đơn vị hàng", không dùng lại "Tồn lỗi": badge đếm đơn vị, còn Tồn lỗi là Slot.
            'defective' => Tab::make('Hàng Lỗi còn trong kho')
                ->badge(fn (): int => StockUnit::query()->defectiveStock()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->defectiveStock()),
            'expired' => Tab::make('Quá hạn')
                ->badge(fn (): int => StockUnit::query()->expired()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->expired()),
        ];
    }
}
