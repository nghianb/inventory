<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchStatus;
use App\Models\Batch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Màn xem trước và kết quả của Lô nhập.
 */
class ViewBatch extends ViewRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Làm mới')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Validating)
                ->action(fn () => $this->batch()->refresh()),
            Action::make('confirm')
                ->label('Xác nhận nhập kho')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (BatchIntake $intake): string => sprintf(
                    'Chỉ %s Đơn vị hàng hợp lệ vào kho; dòng lỗi và dòng trùng bị bỏ. Lô nhập đã xác nhận thì đóng.',
                    number_format($intake->preview(InventoryAction::actor(), $this->batch())->validCount(), 0, ',', '.'),
                ))
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Validated
                    && InventoryAction::actor()->can('confirm', $this->batch()))
                ->action(function (Action $action, BatchIntake $intake): void {
                    InventoryAction::attempt($action, fn () => $intake->confirm(InventoryAction::actor(), $this->batch()));
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã nhập kho phần hợp lệ của Lô nhập.')->send();
                }),
        ];
    }

    private function batch(): Batch
    {
        $record = $this->getRecord();
        assert($record instanceof Batch);

        return $record;
    }
}
