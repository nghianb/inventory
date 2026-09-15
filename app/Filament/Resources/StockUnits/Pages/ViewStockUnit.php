<?php

namespace App\Filament\Resources\StockUnits\Pages;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\StockUnit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Chi tiết Đơn vị hàng. Quản trị Huỷ hàng cả Đơn vị hàng qua modal, gọi StockVoid.
 */
class ViewStockUnit extends ViewRecord
{
    protected static string $resource = StockUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('void')
                ->label('Huỷ hàng')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->modalHeading('Huỷ hàng Đơn vị hàng')
                ->modalDescription('Đơn vị hàng và mọi Slot Còn hàng chuyển Đã huỷ; Slot Đã giao giữ nguyên. Không giải phóng Khoá chống trùng. Hàng hỏng hoặc bị nhà cung cấp thu hồi thì dùng Đánh dấu Lỗi.')
                ->modalSubmitActionLabel('Huỷ hàng')
                ->schema(StockUnitResource::voidSchema())
                ->visible(fn (StockVoid $voids): bool => $voids->canVoidUnit(InventoryAction::actor(), $this->stockUnitRecord()))
                ->action(function (Action $action, StockVoid $voids, array $data): void {
                    InventoryAction::attempt($action, fn () => $voids->voidUnit(InventoryAction::actor(), $this->stockUnitRecord(), VoidReason::from($data['reason']), $data['note'] ?? null));
                    $this->stockUnitRecord()->refresh();

                    Notification::make()->success()->title('Đã Huỷ hàng Đơn vị hàng.')->send();
                }),
        ];
    }

    private function stockUnitRecord(): StockUnit
    {
        $record = $this->getRecord();
        assert($record instanceof StockUnit);

        return $record;
    }
}
