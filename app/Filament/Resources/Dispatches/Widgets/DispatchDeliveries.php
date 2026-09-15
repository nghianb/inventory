<?php

namespace App\Filament\Resources\Dispatches\Widgets;

use App\Filament\Support\InventoryAction;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * Bảng Lần giao trên trang xem Phiếu xuất, nội dung luôn dạng che. Xem mã gọi ContentReveal
 * (Bán hàng trong Hạn bảo hành, quá hạn chỉ Quản trị), mỗi lần ghi Nhật ký xem mã. Nội dung chỉ
 * nằm trong tham số của modal vừa mở: không lưu ở server, nhưng đi trong snapshot Livewire (không
 * mã hoá) tới trình duyệt cho tới khi modal đóng.
 */
class DispatchDeliveries extends TableWidget
{
    #[Locked]
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public function mount(): void
    {
        abort_unless(InventoryAction::actor()->can('view', $this->dispatchRecord()), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Lần giao')
            ->query(fn (): Builder => Delivery::query()
                ->whereIn('dispatch_line_id', DispatchLine::query()->where('dispatch_id', $this->dispatchRecord()->id)->select('id'))
                ->with(['dispatchLine.product', 'slot', 'stockUnit.product.contentFields']))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('dispatchLine.product.name')
                    ->label('Sản phẩm'),
                TextColumn::make('unit')
                    ->label('Đơn vị hàng')
                    ->state(fn (Delivery $record): string => $record->unitLabel()),
                TextColumn::make('content')
                    ->label('Nội dung (đã che)')
                    ->state(fn (Delivery $record): string => collect($record->stockUnit->maskedContent())
                        ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                        ->implode(' · '))
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('delivered_at')
                    ->label('Giao lúc')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('warranty_ends_on')
                    ->label('Hạn bảo hành')
                    ->state(fn (Delivery $record): string => $record->warrantyEndsOn()->format('d/m/Y')),
                TextColumn::make('slot.status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (SlotStatus $state): string => $state->label())
                    ->color(fn (SlotStatus $state): string => $state === SlotStatus::Delivered ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('Xem mã')
                    ->icon(Heroicon::OutlinedEye)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Delivery $record): string => "Xem mã Slot #{$record->slot_id}")
                    ->modalDescription('Nội dung đầy đủ hiện ra để gửi lại cho khách. Lần xem được ghi vào Nhật ký xem mã.')
                    ->modalSubmitActionLabel('Xem mã')
                    ->visible(fn (Delivery $record): bool => app(ContentReveal::class)->canRevealDelivery(InventoryAction::actor(), $record))
                    ->action(function (Action $action, Delivery $record, ContentReveal $reveal): void {
                        $content = InventoryAction::attempt($action, fn () => $reveal->revealDelivery(InventoryAction::actor(), $record));

                        $this->replaceMountedAction('revealedDelivery', [
                            'slot' => $record->slot_id,
                            'message' => (string) $content->message,
                        ]);
                    }),
            ]);
    }

    public function revealedDeliveryAction(): Action
    {
        return Action::make('revealedDelivery')
            ->modalHeading(fn (array $arguments): string => "Nội dung Slot #{$arguments['slot']}")
            ->schema(fn (array $arguments): array => [
                TextEntry::make('message')
                    ->hiddenLabel()
                    ->state((string) ($arguments['message'] ?? ''))
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->copyMessage('Đã copy.'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }

    private function dispatchRecord(): Dispatch
    {
        $record = $this->record;
        assert($record instanceof Dispatch);

        return $record;
    }
}
