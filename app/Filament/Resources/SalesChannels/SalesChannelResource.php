<?php

namespace App\Filament\Resources\SalesChannels;

use App\Filament\Resources\SalesChannels\Pages\ManageSalesChannels;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Models\SalesChannel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Kênh bán trong panel, chỉ Quản trị. Adapter mỏng: mọi thao tác gọi SalesChannelDirectory.
 */
class SalesChannelResource extends Resource
{
    protected static ?string $model = SalesChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $modelLabel = 'kênh bán';

    protected static ?string $pluralModelLabel = 'Kênh bán';

    protected static ?string $navigationLabel = 'Kênh bán';

    protected static ?string $slug = 'kenh-ban';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên')
                ->helperText('Ví dụ Shopee, Facebook, Zalo.')
                ->required()
                ->maxLength(255),
            Toggle::make('requires_external_ref')
                ->label('Bắt buộc mã đơn ngoài')
                ->helperText('Không bắt buộc thì nhân viên để trống sẽ được tự sinh mã PX-YYYYMMDD-NNNN.')
                ->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (SalesChannelType $state): string => $state->label()),
                IconColumn::make('requires_external_ref')
                    ->label('Bắt buộc mã đơn')
                    ->boolean(),
                TextColumn::make('hidden_at')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (SalesChannel $record): string => $record->isHidden() ? 'Ngừng dùng' : 'Đang dùng')
                    ->color(fn (SalesChannel $record): string => $record->isHidden() ? 'gray' : 'success'),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (EditAction $action, SalesChannel $record, array $data, SalesChannelDirectory $channels): SalesChannel => InventoryAction::attempt(
                        $action,
                        fn () => $channels->update(InventoryAction::actor(), $record, self::draftFromForm($data)),
                    )),
                Action::make('hide')
                    ->label('Ngừng dùng')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Kênh bán bị ẩn khỏi form tạo Phiếu xuất; Phiếu xuất cũ giữ nguyên.')
                    ->visible(fn (SalesChannel $record): bool => ! $record->isHidden() && InventoryAction::actor()->can('update', $record))
                    ->action(function (Action $action, SalesChannel $record, SalesChannelDirectory $channels): void {
                        InventoryAction::attempt($action, fn () => $channels->hide(InventoryAction::actor(), $record));

                        Notification::make()->success()->title('Đã ngừng dùng Kênh bán.')->send();
                    }),
                Action::make('unhide')
                    ->label('Dùng lại')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (SalesChannel $record): bool => $record->isHidden() && InventoryAction::actor()->can('update', $record))
                    ->action(function (Action $action, SalesChannel $record, SalesChannelDirectory $channels): void {
                        InventoryAction::attempt($action, fn () => $channels->unhide(InventoryAction::actor(), $record));

                        Notification::make()->success()->title('Kênh bán dùng lại được.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSalesChannels::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function draftFromForm(array $data): SalesChannelDraft
    {
        return new SalesChannelDraft(
            name: (string) $data['name'],
            requiresExternalRef: (bool) ($data['requires_external_ref'] ?? false),
        );
    }
}
