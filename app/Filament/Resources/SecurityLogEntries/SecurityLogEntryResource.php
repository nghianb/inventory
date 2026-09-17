<?php

namespace App\Filament\Resources\SecurityLogEntries;

use App\Filament\Resources\SecurityLogEntries\Pages\ManageSecurityLogEntries;
use App\Filament\Support\NavGroup;
use App\Inventory\Security\SecurityEvent;
use App\Models\SecurityLogEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Xem Nhật ký bảo mật. Chỉ đọc; quyền nằm ở SecurityLogEntryPolicy.
 */
class SecurityLogEntryResource extends Resource
{
    protected static ?string $model = SecurityLogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::NhatKy;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'dòng Nhật ký bảo mật';

    protected static ?string $pluralModelLabel = 'Nhật ký bảo mật';

    protected static ?string $navigationLabel = 'Nhật ký bảo mật';

    protected static ?string $slug = 'nhat-ky-bao-mat';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Thời điểm')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Sự kiện')
                    ->formatStateUsing(fn (SecurityEvent $state): string => $state->label())
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Nhân viên')
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('actor.name')
                    ->label('Người thực hiện')
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('IP'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Sự kiện')
                    ->options(collect(SecurityEvent::cases())
                        ->mapWithKeys(fn (SecurityEvent $event): array => [$event->value => $event->label()])
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSecurityLogEntries::route('/'),
        ];
    }
}
