<?php

namespace App\Filament\Resources\RevealLogEntries;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\RevealLogEntries\Pages\ManageRevealLogEntries;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Inventory\Reveal\RevealContextType;
use App\Models\RevealLogEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Xem Nhật ký xem mã. Chỉ đọc; quyền nằm ở RevealLogEntryPolicy.
 */
class RevealLogEntryResource extends Resource
{
    protected static ?string $model = RevealLogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $modelLabel = 'dòng Nhật ký xem mã';

    protected static ?string $pluralModelLabel = 'Nhật ký xem mã';

    protected static ?string $navigationLabel = 'Nhật ký xem mã';

    protected static ?string $slug = 'nhat-ky-xem-ma';

    public static function table(Table $table): Table
    {
        return static::configureColumns($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->filters([
                SelectFilter::make('context')
                    ->label('Ngữ cảnh')
                    ->options(collect(RevealContextType::cases())
                        ->mapWithKeys(fn (RevealContextType $type): array => [$type->value => $type->label()])
                        ->all()),
            ]);
    }

    /**
     * Cột dùng chung cho màn Nhật ký xem mã và lịch sử xem trên chi tiết Đơn vị hàng.
     */
    public static function configureColumns(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Thời điểm')
                    ->dateTime('d/m/Y H:i:s'),
                TextColumn::make('actor')
                    ->label('Ai')
                    ->state(fn (RevealLogEntry $record): string => $record->actorLabel()),
                TextColumn::make('context')
                    ->label('Ngữ cảnh')
                    ->badge()
                    ->formatStateUsing(fn (RevealContextType $state, RevealLogEntry $record): string => $record->context_id === null
                        ? $state->label()
                        : "{$state->label()} #{$record->context_id}")
                    ->url(fn (RevealLogEntry $record): ?string => match (true) {
                        $record->context_id === null => null,
                        $record->context === RevealContextType::Batch => BatchResource::getUrl('view', ['record' => $record->context_id]),
                        $record->context === RevealContextType::SupplierClaim => SupplierClaimResource::getUrl('view', ['record' => $record->context_id]),
                        default => null,
                    }),
                TextColumn::make('slot_id')
                    ->label('Slot')
                    ->prefix('#')
                    ->placeholder('—'),
                TextColumn::make('stock_unit_id')
                    ->label('Đơn vị hàng')
                    ->prefix('#')
                    ->placeholder('—')
                    ->url(fn (RevealLogEntry $record): ?string => $record->stock_unit_id === null
                        ? null
                        : StockUnitResource::getUrl('view', ['record' => $record->stock_unit_id])),
                TextColumn::make('reason')
                    ->label('Lý do')
                    ->wrap(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRevealLogEntries::route('/'),
        ];
    }
}
