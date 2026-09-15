<?php

namespace App\Filament\Resources\StockUnits\RelationManagers;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Reveal\RevealActor;
use App\Inventory\Reveal\RevealContext;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\Slot;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Slot của một Đơn vị hàng. Quản trị xem nội dung Slot Còn hàng kèm lý do tự do qua
 * ContentReveal. Nội dung chỉ nằm trong tham số của modal vừa mở: không lưu ở server (không qua
 * session hay thông báo), nhưng đi trong snapshot Livewire (không mã hoá) tới trình duyệt và
 * quay lại theo mỗi request cho tới khi modal đóng.
 */
class SlotsRelationManager extends RelationManager
{
    protected static string $relationship = 'slots';

    protected static ?string $title = 'Slot';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InventoryAction::actor()->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (SlotStatus $state): string => $state->label()),
                TextColumn::make('cost')
                    ->label('Giá vốn')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' ₫')
                    ->visible(fn (): bool => app(RoleGate::class)->allows(InventoryAction::actor(), Role::NhapKho)),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('Xem nội dung')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (Slot $record): string => "Xem nội dung Slot #{$record->id}")
                    ->modalDescription('Lần xem được ghi vào Nhật ký xem mã kèm lý do.')
                    ->modalSubmitActionLabel('Xem nội dung')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Lý do')
                            ->required()
                            ->rows(2),
                    ])
                    ->visible(fn (Slot $record): bool => app(ContentReveal::class)->canRevealInStock(InventoryAction::actor(), $record))
                    ->action(function (Action $action, Slot $record, array $data): void {
                        $content = InventoryAction::attempt($action, fn () => app(ContentReveal::class)->reveal(
                            RevealActor::staff(InventoryAction::actor()),
                            $record,
                            RevealContext::inStock(),
                            (string) $data['reason'],
                        ));

                        $this->replaceMountedAction('revealedContent', [
                            'slot' => $record->id,
                            'fields' => array_map(fn (string $label, string $value): array => ['label' => $label, 'value' => $value], array_keys($content->fields), $content->fields),
                        ]);
                    }),
                Action::make('void')
                    ->label('Huỷ hàng')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->modalHeading(fn (Slot $record): string => "Huỷ hàng Slot #{$record->id}")
                    ->modalDescription('Slot chuyển Đã huỷ, không bán hay giao được nữa. Không giải phóng Khoá chống trùng.')
                    ->modalSubmitActionLabel('Huỷ hàng')
                    ->schema(StockUnitResource::voidSchema())
                    ->visible(fn (Slot $record): bool => app(StockVoid::class)->canVoidSlot(InventoryAction::actor(), $record))
                    ->action(function (Action $action, Slot $record, array $data): void {
                        InventoryAction::attempt($action, fn () => app(StockVoid::class)->voidSlot(InventoryAction::actor(), $record, VoidReason::from($data['reason']), $data['note'] ?? null));

                        Notification::make()->success()->title("Đã Huỷ hàng Slot #{$record->id}.")->send();
                    }),
            ]);
    }

    public function revealedContentAction(): Action
    {
        return Action::make('revealedContent')
            ->modalHeading(fn (array $arguments): string => "Nội dung Slot #{$arguments['slot']}")
            ->schema(fn (array $arguments): array => array_map(
                fn (array $field, int $index): TextEntry => TextEntry::make("field_{$index}")
                    ->label($field['label'])
                    ->state($field['value'])
                    ->copyable(),
                $arguments['fields'] ?? [],
                array_keys($arguments['fields'] ?? []),
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }
}
