<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchStatus;
use App\Models\Batch;
use App\Models\BatchLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Màn xem trước và kết quả của Lô nhập.
 */
class ViewBatch extends ViewRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        $stockDuplicates = fn (BatchIntake $intake): int => $intake->preview(InventoryAction::actor(), $this->batch())->stockDuplicateCount();

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
                    'Chỉ %s Đơn vị hàng nhập được vào kho; dòng lỗi và dòng trùng bị bỏ. Lô nhập đã xác nhận thì đóng.',
                    number_format($intake->preview(InventoryAction::actor(), $this->batch())->importCount(), 0, ',', '.'),
                ))
                ->schema([
                    Checkbox::make('skip_stock_duplicates')
                        ->label(fn (BatchIntake $intake): string => sprintf(
                            'Tôi xác nhận bỏ qua %s dòng trùng trong kho.',
                            number_format($stockDuplicates($intake), 0, ',', '.'),
                        ))
                        ->accepted()
                        ->visible(fn (BatchIntake $intake): bool => $stockDuplicates($intake) > 0),
                ])
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Validated
                    && InventoryAction::actor()->can('confirm', $this->batch()))
                ->action(function (Action $action, BatchIntake $intake, array $data): void {
                    InventoryAction::attempt($action, fn () => $intake->confirm(
                        InventoryAction::actor(),
                        $this->batch(),
                        skipStockDuplicates: (bool) ($data['skip_stock_duplicates'] ?? false),
                    ));
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã nhập kho phần hợp lệ của Lô nhập.')->send();
                }),
            Action::make('downloadRejected')
                ->label('Tải CSV dòng bị bỏ')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalDescription('Mỗi lần tải được ghi vào Nhật ký xem mã. Chỉ tải được ở màn xem trước hoặc ngay sau khi xác nhận.')
                ->modalSubmitActionLabel('Tải CSV')
                ->schema([
                    Select::make('line')
                        ->label('Dòng nhập')
                        ->options(fn (): array => $this->rejectedLines()->mapWithKeys(fn (BatchLine $line): array => [
                            $line->id => sprintf('%s (%s dòng bị bỏ)', $line->product->name, number_format(count($line->preview['rejected'] ?? []), 0, ',', '.')),
                        ])->all())
                        ->default(fn (): ?int => $this->rejectedLines()->count() === 1 ? $this->rejectedLines()->first()?->id : null)
                        ->selectablePlaceholder(false)
                        ->required(),
                ])
                ->visible(fn (): bool => $this->rejectedLines()->isNotEmpty())
                ->action(function (Action $action, BatchIntake $intake, array $data): StreamedResponse {
                    $export = InventoryAction::attempt($action, fn () => $intake->rejectedLines(
                        InventoryAction::actor(),
                        $this->rejectedLines()->firstOrFail(fn (BatchLine $line): bool => $line->id === (int) $data['line']),
                    ));

                    return response()->streamDownload(fn () => print ($export->csv), $export->fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Action::make('discard')
                ->label('Bỏ Lô nhập')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Nội dung tạm của Lô nhập bị xoá, không xác nhận được nữa.')
                ->visible(fn (): bool => in_array($this->batch()->status, BatchStatus::pending(), true)
                    && InventoryAction::actor()->can('confirm', $this->batch()))
                ->action(function (Action $action, BatchIntake $intake): void {
                    InventoryAction::attempt($action, fn () => $intake->discard(InventoryAction::actor(), $this->batch()));
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã bỏ Lô nhập.')->send();
                }),
        ];
    }

    /**
     * Dòng nhập mà nhân viên đang đăng nhập tải được dòng bị bỏ lúc này.
     *
     * @return Collection<int, BatchLine>
     */
    private function rejectedLines(): Collection
    {
        return app(BatchIntake::class)->downloadableRejectedLines(InventoryAction::actor(), $this->batch());
    }

    private function batch(): Batch
    {
        $record = $this->getRecord();
        assert($record instanceof Batch);

        return $record;
    }
}
