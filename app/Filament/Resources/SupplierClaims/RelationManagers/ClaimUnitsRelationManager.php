<?php

namespace App\Filament\Resources\SupplierClaims\RelationManagers;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\SupplierClaim;
use App\Models\SupplierClaimUnit;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Đơn vị hàng của một Khiếu nại nhà cung cấp, kể cả đã gỡ. Xem mã gọi ContentReveal (ghi Nhật ký xem
 * mã ngữ cảnh Khiếu nại nhà cung cấp); Gỡ khỏi Nháp gọi SupplierClaims. Nội dung chỉ nằm trong tham số
 * của modal vừa mở.
 */
class ClaimUnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'claimUnits';

    protected static ?string $title = 'Đơn vị hàng';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InventoryAction::actor()->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    #[On('refresh-claim-units')]
    public function refreshUnits(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('stockUnit.product.contentFields'))
            ->paginated(false)
            ->columns([
                TextColumn::make('stock_unit_id')
                    ->label('Đơn vị hàng')
                    ->prefix('#')
                    ->url(fn (SupplierClaimUnit $record): string => StockUnitResource::getUrl('view', ['record' => $record->stock_unit_id])),
                TextColumn::make('stockUnit.product.name')
                    ->label('Sản phẩm'),
                TextColumn::make('content')
                    ->label('Nội dung (đã che)')
                    ->state(fn (SupplierClaimUnit $record): string => collect($record->stockUnit->maskedContent())
                        ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                        ->implode(' · '))
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('stockUnit.status')
                    ->label('Trạng thái Đơn vị hàng')
                    ->badge()
                    ->formatStateUsing(fn (StockUnitStatus $state): string => $state->label()),
                TextColumn::make('membership')
                    ->label('Trong khiếu nại')
                    ->state(fn (SupplierClaimUnit $record): string => match (true) {
                        $record->active => 'Có',
                        $record->removal_reason !== null => "Đã gỡ: {$record->removal_reason}",
                        default => 'Khiếu nại đã huỷ',
                    })
                    ->color(fn (SupplierClaimUnit $record): string => $record->active ? 'success' : 'gray')
                    ->wrap(),
                TextColumn::make('outcome')
                    ->label('Kết quả')
                    ->badge()
                    ->formatStateUsing(fn (ClaimOutcome $state): string => $state->label())
                    ->color(fn (ClaimOutcome $state): string => $state->color())
                    ->placeholder('—'),
                TextColumn::make('refund_amount')
                    ->label('Bồi hoàn')
                    ->formatStateUsing(fn (int $state): string => SupplierClaimResource::money($state))
                    ->description(fn (SupplierClaimUnit $record): ?string => $record->refunded_on?->format('d/m/Y'))
                    ->placeholder('—'),
                TextColumn::make('outcome_note')
                    ->label('Ghi chú kết quả')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('Xem mã')
                    ->icon(Heroicon::OutlinedEye)
                    ->requiresConfirmation()
                    ->modalHeading(fn (SupplierClaimUnit $record): string => "Xem mã Đơn vị hàng #{$record->stock_unit_id}")
                    ->modalDescription('Nội dung đầy đủ để gửi bằng chứng cho Nhà cung cấp. Lần xem được ghi vào Nhật ký xem mã.')
                    ->modalSubmitActionLabel('Xem mã')
                    ->visible(fn (SupplierClaimUnit $record): bool => app(ContentReveal::class)->canRevealClaimUnit(InventoryAction::actor(), $record))
                    ->action(function (Action $action, SupplierClaimUnit $record): void {
                        $content = InventoryAction::attempt($action, fn () => app(ContentReveal::class)->revealClaimUnit(InventoryAction::actor(), $record));

                        $this->replaceMountedAction('revealedContent', [
                            'unit' => $record->stock_unit_id,
                            'fields' => array_map(fn (string $label, string $value): array => ['label' => $label, 'value' => $value], array_keys($content->fields), $content->fields),
                        ]);
                    }),
                Action::make('remove')
                    ->label('Gỡ')
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (SupplierClaimUnit $record): string => "Gỡ Đơn vị hàng #{$record->stock_unit_id} khỏi Khiếu nại")
                    ->modalDescription('Đơn vị hàng quay lại danh sách Lỗi chưa khiếu nại.')
                    ->visible(fn (SupplierClaimUnit $record): bool => $record->active && app(SupplierClaims::class)->canEdit(InventoryAction::actor(), $this->claim()))
                    ->action(function (Action $action, SupplierClaimUnit $record): void {
                        InventoryAction::attempt($action, fn () => app(SupplierClaims::class)->removeUnit(InventoryAction::actor(), $record));

                        Notification::make()->success()->title("Đã gỡ Đơn vị hàng #{$record->stock_unit_id} khỏi Khiếu nại.")->send();
                    }),
            ]);
    }

    public function revealedContentAction(): Action
    {
        return Action::make('revealedContent')
            ->modalHeading(fn (array $arguments): string => "Nội dung Đơn vị hàng #{$arguments['unit']}")
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

    private function claim(): SupplierClaim
    {
        $claim = $this->getOwnerRecord();
        assert($claim instanceof SupplierClaim);

        return $claim;
    }
}
