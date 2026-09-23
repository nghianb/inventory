<?php

namespace App\Filament\Resources\ApiKeys;

use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Support\Clipboard;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Api\ApiKeys;
use App\Inventory\Api\IssuedApiKey;
use App\Inventory\Dispatch\SalesChannelType;
use App\Models\ApiKey;
use App\Models\SalesChannel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Khoá API trong panel, chỉ Quản trị. Adapter mỏng: tạo, xoay và thu hồi đều gọi {@see ApiKeys},
 * nơi ghi Nhật ký bảo mật. Giá trị khoá chỉ hiện đúng một lần, ngay sau khi tạo hoặc xoay.
 */
class ApiKeyResource extends Resource
{
    /**
     * Tên action của modal hiện khoá vừa cấp. {@see ManageApiKeys::newApiKeySecretAction()} khai nó
     * trên trang để Filament mount được theo tên.
     */
    public const SECRET_ACTION = 'newApiKeySecret';

    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::HeThong;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'khoá API';

    protected static ?string $pluralModelLabel = 'Khoá API';

    protected static ?string $navigationLabel = 'Khoá API';

    protected static ?string $slug = 'khoa-api';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('sales_channel_id')
                ->label('Kênh bán')
                ->options(fn (): array => SalesChannel::query()
                    ->usable()
                    ->where('type', SalesChannelType::Api)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->helperText('Chỉ Kênh bán loại API mới có Khoá API.'),
            TextInput::make('label')
                ->label('Tên gợi nhớ')
                ->maxLength(255)
                ->helperText('Ví dụ "Website chính", "Bản thử". Không bắt buộc.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['salesChannel', 'creator']))
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('salesChannel.name')
                    ->label('Kênh bán'),
                TextColumn::make('label')
                    ->label('Tên gợi nhớ')
                    ->placeholder('Không có'),
                TextColumn::make('prefix')
                    ->label('Khoá')
                    ->state(fn (ApiKey $record): string => "{$record->prefix}…")
                    ->description('Kho chỉ lưu hash của khoá.'),
                TextColumn::make('revoked_at')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'Đã thu hồi' : 'Đang dùng')
                    ->color(fn (ApiKey $record): string => $record->isRevoked() ? 'gray' : 'success'),
                TextColumn::make('last_used_at')
                    ->label('Dùng lần cuối')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Chưa dùng'),
                TextColumn::make('creator.name')
                    ->label('Người tạo'),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('rotate')
                    ->label('Xoay khoá')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Cấp khoá mới cho kênh này; khoá cũ vẫn dùng được cho tới khi bạn thu hồi. Khoá mới chỉ hiện một lần.')
                    ->visible(fn (ApiKey $record): bool => ! $record->isRevoked() && InventoryAction::actor()->can('create', ApiKey::class))
                    ->action(function (Action $action, ApiKey $record, ApiKeys $keys, ManageApiKeys $livewire): void {
                        self::announce($livewire, InventoryAction::attempt($action, fn (): IssuedApiKey => $keys->rotate(InventoryAction::actor(), $record)));
                    }),
                Action::make('revoke')
                    ->label('Thu hồi')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Khoá hết gọi vào kho được ngay. Bản ghi vẫn giữ vì Nhật ký xem mã tham chiếu tới khoá này.')
                    ->visible(fn (ApiKey $record): bool => ! $record->isRevoked() && InventoryAction::actor()->can('create', ApiKey::class))
                    ->action(function (Action $action, ApiKey $record, ApiKeys $keys): void {
                        InventoryAction::attempt($action, fn () => $keys->revoke(InventoryAction::actor(), $record));

                        Notification::make()->success()->title('Đã thu hồi Khoá API.')->send();
                    }),
            ]);
    }

    /**
     * Hiện giá trị khoá đúng một lần, thay cho modal vừa tạo hoặc vừa xoay. Gọi được từ cả header
     * action lẫn record action vì stack mount là của trang.
     */
    public static function announce(ManageApiKeys $livewire, IssuedApiKey $issued): void
    {
        $livewire->replaceMountedAction(self::SECRET_ACTION, [
            'secret' => $issued->secret,
            'channel' => $issued->key->salesChannel->name,
            'label' => $issued->key->label,
        ]);
    }

    /**
     * Modal khoá vừa cấp: kho lưu hash nên đây là lần duy nhất đọc được giá trị. Không đóng được
     * bằng Esc, bằng click ra ngoài hay bằng nút X — lối ra duy nhất là nút đã-lưu, để không mất
     * khoá vì một cú bấm lạc.
     */
    public static function secretAction(): Action
    {
        return Action::make(self::SECRET_ACTION)
            ->modalHeading('Khoá API mới — chỉ hiện một lần')
            ->modalDescription(fn (array $arguments): string => $arguments['label'] === null
                ? "Kênh bán {$arguments['channel']}"
                : "Kênh bán {$arguments['channel']} · Tên gợi nhớ \"{$arguments['label']}\"")
            ->modalWidth(Width::TwoExtraLarge)
            ->modalCloseButton(false)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->schema(fn (Schema $schema, array $arguments): Schema => $schema->columns(1)->components([
                Callout::make('Kho chỉ lưu hash của khoá. Đóng cửa sổ này là không xem lại được; muốn khoá khác phải Xoay khoá và sửa lại cấu hình website.')
                    ->warning(),
                TextEntry::make('secret')
                    ->label('Khoá API')
                    ->state($arguments['secret'])
                    ->fontFamily(FontFamily::Mono),
                Actions::make([
                    Action::make('copySecret')
                        ->label('Copy khoá')
                        ->icon(Heroicon::OutlinedClipboardDocument)
                        ->alpineClickHandler(Clipboard::copy($arguments['secret'], 'Đã copy Khoá API.')),
                ]),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tôi đã lưu khoá');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApiKeys::route('/'),
        ];
    }
}
