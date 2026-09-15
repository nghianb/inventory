<?php

namespace App\Filament\Resources\StockUnits;

use App\Filament\Resources\StockUnits\Pages\ListStockUnits;
use App\Filament\Support\InventoryAction;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Slot;
use App\Models\StockUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Đơn vị hàng trong panel, chỉ đọc. Nội dung luôn ở dạng che: trang này không bao giờ
 * giải mã. Bán hàng không thấy Giá vốn và Nhà cung cấp.
 */
class StockUnitResource extends Resource
{
    protected static ?string $model = StockUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'đơn vị hàng';

    protected static ?string $pluralModelLabel = 'Đơn vị hàng';

    protected static ?string $navigationLabel = 'Đơn vị hàng';

    protected static ?string $slug = 'don-vi-hang';

    public static function table(Table $table): Table
    {
        $seesCost = fn (): bool => app(RoleGate::class)->allows(InventoryAction::actor(), Role::NhapKho);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.contentFields', 'slots', 'batchLine.batch.supplier']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('product.name')
                    ->label('Sản phẩm'),
                TextColumn::make('masked_content')
                    ->label('Nội dung')
                    ->state(fn (StockUnit $record): string => collect($record->maskedContent())
                        ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                        ->implode(' · ')),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (StockUnitStatus $state): string => $state->label()),
                TextColumn::make('slot_summary')
                    ->label('Slot')
                    ->state(fn (StockUnit $record): string => $record->slots
                        ->countBy(fn (Slot $slot): string => $slot->status->value)
                        ->map(fn (int $count, string $status): string => $count.' '.SlotStatus::from($status)->label())
                        ->implode(', ')),
                TextColumn::make('expires_on')
                    ->label('Hạn sử dụng')
                    ->date('d/m/Y')
                    ->placeholder('Không có')
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Giá vốn')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.').' ₫')
                    ->visible($seesCost),
                TextColumn::make('batchLine.batch.supplier.name')
                    ->label('Nhà cung cấp')
                    ->visible($seesCost),
                TextColumn::make('batchLine.batch_id')
                    ->label('Lô nhập')
                    ->prefix('#'),
                TextColumn::make('created_at')
                    ->label('Nhập lúc')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('product')
                    ->label('Sản phẩm')
                    ->relationship('product', 'name'),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(collect(StockUnitStatus::cases())->mapWithKeys(fn (StockUnitStatus $status): array => [$status->value => $status->label()])->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockUnits::route('/'),
        ];
    }
}
