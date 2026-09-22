<?php

namespace App\Filament\Resources\SalesChannels;

use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\SalesChannels\Pages\ManageSalesChannels;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Dispatch\SalesChannelDirectory;
use App\Inventory\Dispatch\SalesChannelDraft;
use App\Inventory\Dispatch\SalesChannelType;
use App\Models\SalesChannel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Kênh bán trong panel, chỉ Quản trị. Adapter mỏng: mọi thao tác gọi SalesChannelDirectory.
 * Kênh loại API có thêm hạn Giữ hàng và cờ bắt buộc Giá bán; Khoá API của kênh quản lý ở
 * {@see ApiKeyResource}.
 */
class SalesChannelResource extends Resource
{
    protected static ?string $model = SalesChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::XuatHang;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'kênh bán';

    protected static ?string $pluralModelLabel = 'Kênh bán';

    protected static ?string $navigationLabel = 'Kênh bán';

    protected static ?string $slug = 'kenh-ban';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $isApi = fn (Get $get): bool => $get('type') === SalesChannelType::Api->value;

        return $schema->columns(1)->components([
            TextInput::make('name')
                ->label('Tên')
                ->helperText('Ví dụ Shopee, Facebook, Zalo, Website.')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label('Loại')
                ->options(fn (): array => collect(SalesChannelType::cases())
                    ->mapWithKeys(fn (SalesChannelType $type): array => [$type->value => $type->label()])
                    ->all())
                ->default(SalesChannelType::Manual->value)
                ->selectablePlaceholder(false)
                ->live()
                ->helperText('Kênh API: website gọi vào kho bằng Khoá API, nhân viên không tạo Phiếu xuất tay. Đã có Phiếu xuất hoặc Khoá API thì không đổi loại được.'),
            Toggle::make('requires_external_ref')
                ->label('Bắt buộc mã đơn ngoài')
                ->helperText('Không bắt buộc thì nhân viên để trống sẽ được tự sinh mã PX-YYYYMMDD-NNNN. Đơn qua API luôn phải có mã đơn.')
                ->default(false),
            TextInput::make('hold_minutes')
                ->label('Hạn Giữ hàng (phút)')
                ->integer()
                ->minValue(1)
                ->maxValue(1_440)
                ->required()
                ->default(fn (): int => (int) config('inventory.api.hold_minutes'))
                ->helperText('Do kho quy định, website không tự đặt.')
                ->visible($isApi),
            Toggle::make('requires_sale_price')
                ->label('Bắt buộc Giá bán')
                ->default(true)
                ->helperText('Tắt thì đơn web không có Giá bán vẫn vào được, nhưng báo cáo Lãi/lỗ sẽ thiếu doanh thu của các đơn ấy.')
                ->visible($isApi),
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
                TextColumn::make('hold_minutes')
                    ->label('Hạn Giữ hàng')
                    ->state(fn (SalesChannel $record): ?string => $record->isApi() ? "{$record->hold_minutes} phút" : null)
                    ->placeholder('—'),
                IconColumn::make('requires_sale_price')
                    ->label('Bắt buộc Giá bán')
                    ->boolean()
                    ->visible(fn (): bool => SalesChannel::query()->where('type', SalesChannelType::Api)->exists()),
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
                    ->modalDescription('Kênh bán bị ẩn khỏi form tạo Phiếu xuất và Khoá API của kênh hết gọi vào kho được; Phiếu xuất cũ giữ nguyên.')
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
            type: SalesChannelType::from((string) ($data['type'] ?? SalesChannelType::Manual->value)),
            requiresExternalRef: (bool) ($data['requires_external_ref'] ?? false),
            // Trường của kênh API không hiện với kênh thủ công: để module lấy mặc định.
            holdMinutes: filled($data['hold_minutes'] ?? null) ? (int) $data['hold_minutes'] : null,
            requiresSalePrice: isset($data['requires_sale_price']) ? (bool) $data['requires_sale_price'] : null,
        );
    }
}
