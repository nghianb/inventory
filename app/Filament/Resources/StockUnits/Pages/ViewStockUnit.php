<?php

namespace App\Filament\Resources\StockUnits\Pages;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Stock\StockDefect;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\StockUnit;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Chi tiết Đơn vị hàng. Quản trị Huỷ hàng cả Đơn vị hàng (StockVoid), Đánh dấu Lỗi hoặc Khôi phục
 * (StockDefect) qua modal.
 */
class ViewStockUnit extends ViewRecord
{
    protected static string $resource = StockUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markDefective')
                ->label('Đánh dấu Lỗi')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->modalHeading('Đánh dấu Lỗi Đơn vị hàng')
                ->modalDescription('Khi nhà cung cấp thu hồi hoặc phát hiện hỏng trong kho, không cần Báo lỗi. Slot Còn hàng thành Tồn lỗi, không bán được. Hệ thống liệt kê Lần giao bị ảnh hưởng để liên hệ khách, không tự Báo lỗi hay Đổi hàng.')
                ->modalSubmitActionLabel('Đánh dấu Lỗi')
                ->schema(self::reasonSchema())
                ->visible(fn (StockDefect $defects): bool => $defects->canMarkDefective(InventoryAction::actor(), $this->stockUnitRecord()))
                ->action(function (Action $action, StockDefect $defects, array $data): void {
                    $affected = InventoryAction::attempt($action, fn () => $defects->markDefective(InventoryAction::actor(), $this->stockUnitRecord(), (string) $data['reason']));
                    $this->stockUnitRecord()->refresh();

                    Notification::make()
                        ->success()
                        ->title('Đã Đánh dấu Lỗi Đơn vị hàng.')
                        ->body($affected === [] ? 'Không có Lần giao bị ảnh hưởng.' : count($affected).' Lần giao bị ảnh hưởng: xem danh sách để liên hệ khách.')
                        ->send();
                }),
            Action::make('restore')
                ->label('Khôi phục')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('success')
                ->modalHeading('Khôi phục Đơn vị hàng')
                ->modalDescription('Khi nhà cung cấp sửa được hàng. Đơn vị hàng về Hoạt động, Slot Còn hàng bán lại được. Báo lỗi và Đổi hàng đã làm giữ nguyên.')
                ->modalSubmitActionLabel('Khôi phục')
                ->schema(self::reasonSchema())
                ->visible(fn (StockDefect $defects): bool => $defects->canRestore(InventoryAction::actor(), $this->stockUnitRecord()))
                ->action(function (Action $action, StockDefect $defects, array $data): void {
                    InventoryAction::attempt($action, fn () => $defects->restore(InventoryAction::actor(), $this->stockUnitRecord(), (string) $data['reason']));
                    $this->stockUnitRecord()->refresh();

                    Notification::make()->success()->title('Đã Khôi phục Đơn vị hàng.')->send();
                }),
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

    /**
     * @return list<Textarea>
     */
    private static function reasonSchema(): array
    {
        return [
            Textarea::make('reason')
                ->label('Lý do')
                ->required()
                ->rows(2),
        ];
    }

    private function stockUnitRecord(): StockUnit
    {
        $record = $this->getRecord();
        assert($record instanceof StockUnit);

        return $record;
    }
}
