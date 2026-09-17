<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Catalog\ProductType;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\DispatchFreeze;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Dispatch\RecordedDeliveryDraft;
use App\Inventory\Dispatch\RecordedLostDelivery;
use App\Inventory\Dispatch\RecordedSlot;
use App\Inventory\Stock\ContentExposure;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Slot;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trang sự cố kho của Quản trị, gom ba việc phải làm được **trong lúc** kho đang dừng: bật/tắt Tạm
 * dừng xuất kho, Ghi nhận giao bù các lần giao mất khi khôi phục từ backup, và xử lý hàng bị coi là
 * đã lộ (Huỷ hàng hàng loạt theo Sản phẩm, cùng danh sách Lần giao bị ảnh hưởng để liên hệ khách).
 *
 * Adapter mỏng: mọi quy tắc nằm ở DispatchFreeze, RecordedLostDelivery và ContentExposure.
 */
class DispatchFreezePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Tạm dừng xuất kho';

    protected static ?string $title = 'Tạm dừng xuất kho';

    protected static ?string $slug = 'tam-dung-xuat-kho';

    /** Phạm vi Huỷ hàng hàng loạt quét cả kho, đúng kịch bản lộ cả khoá lẫn dữ liệu của ADR 0001. */
    private const SCOPE_ALL = 'all';

    public static function canAccess(): bool
    {
        return app(RoleGate::class)->allows(InventoryAction::actor(), Role::QuanTri);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /**
     * Badge đỏ trên điều hướng của mọi màn: kho đang dừng là chuyện nhân viên phải thấy ngay, chứ
     * không phải chỉ thấy khi mở đúng trang này.
     */
    public static function getNavigationBadge(): ?string
    {
        return app(DispatchFreeze::class)->state()->isFrozen() ? 'Đang dừng' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        $state = fn () => app(DispatchFreeze::class)->state();

        return $schema->components([
            Callout::make('Kho đang Tạm dừng xuất kho')
                ->danger()
                ->description(fn (): string => sprintf(
                    'Không Kênh bán nào, kể cả kênh API, Giữ hàng hay Giao hàng được. Nhập hàng, Huỷ hàng và Ghi nhận giao bù vẫn chạy. %s bật lúc %s. Lý do: %s',
                    $state()->actorLabel(),
                    $state()->frozenAt?->format('d/m/Y H:i') ?? '',
                    $state()->reason ?? '',
                ))
                ->visible(fn (): bool => $state()->isFrozen())
                ->columnSpanFull(),
            Callout::make('Kho đang xuất hàng bình thường')
                ->info()
                ->description('Bật Tạm dừng xuất kho sau khi khôi phục từ backup, hoặc khi nghi nội dung kho bị lộ.')
                ->visible(fn (): bool => ! $state()->isFrozen())
                ->columnSpanFull(),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('freeze')
                ->label('Bật Tạm dừng xuất kho')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->modalHeading('Bật Tạm dừng xuất kho')
                ->modalDescription('Toàn kho ngừng Giữ hàng và Giao hàng cho tới khi Quản trị tắt. Các lần giao đang chạy dở vẫn chạy nốt. Ghi Nhật ký bảo mật.')
                ->modalSubmitActionLabel('Bật')
                ->schema(self::reasonSchema('Vì sao dừng kho'))
                ->visible(fn (DispatchFreeze $freeze): bool => ! $freeze->state()->isFrozen())
                ->action(function (Action $action, DispatchFreeze $freeze, array $data): void {
                    InventoryAction::attempt($action, fn () => $freeze->freeze(InventoryAction::actor(), (string) $data['reason']));

                    Notification::make()->success()->title('Kho đã vào Tạm dừng xuất kho.')->send();
                }),
            Action::make('unfreeze')
                ->label('Tắt Tạm dừng xuất kho')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->modalHeading('Tắt Tạm dừng xuất kho')
                ->modalDescription('Kho xuất hàng lại bình thường. Chỉ tắt sau khi đã đối chiếu xong các đơn phát sinh sau mốc khôi phục. Ghi Nhật ký bảo mật.')
                ->modalSubmitActionLabel('Tắt')
                ->schema(self::reasonSchema('Vì sao mở lại kho'))
                ->visible(fn (DispatchFreeze $freeze): bool => $freeze->state()->isFrozen())
                ->action(function (Action $action, DispatchFreeze $freeze, array $data): void {
                    InventoryAction::attempt($action, fn () => $freeze->unfreeze(InventoryAction::actor(), (string) $data['reason']));

                    Notification::make()->success()->title('Kho đã xuất hàng lại bình thường.')->send();
                }),
            Action::make('lookupSlot')
                ->label('Ghi nhận giao bù')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->modalHeading('Ghi nhận giao bù: tìm Slot')
                ->modalDescription('Dán Khoá chống trùng khách đang giữ (mã hoặc tên đăng nhập). Kết quả chỉ hiện dạng che và không ghi Nhật ký xem mã.')
                ->modalSubmitActionLabel('Tìm')
                ->visible(fn (RecordedLostDelivery $recorded): bool => $recorded->canRecord(InventoryAction::actor()))
                ->schema([
                    Textarea::make('dedupe_key')
                        ->label('Khoá chống trùng')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (Action $action, RecordedLostDelivery $recorded, array $data): void {
                    $candidates = InventoryAction::attempt($action, fn () => $recorded->candidates(InventoryAction::actor(), (string) $data['dedupe_key']));

                    $this->replaceMountedAction('recordLostDelivery', [
                        'candidates' => collect($candidates)
                            ->mapWithKeys(fn (RecordedSlot $slot): array => [$slot->slotId => $slot->label()])
                            ->all(),
                    ]);
                }),
            Action::make('voidExposed')
                ->label('Huỷ hàng hàng loạt vì lộ nội dung')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->modalHeading('Huỷ hàng hàng loạt vì lộ nội dung')
                ->modalDescription('Mọi Đơn vị hàng Hoạt động trong phạm vi chuyển Đã huỷ với lý do Lộ nội dung, cùng mọi Slot Còn hàng của chúng. Slot Đã giao giữ nguyên: dùng danh sách bên dưới để liên hệ khách. Không giải phóng Khoá chống trùng.')
                ->modalSubmitActionLabel('Huỷ hàng')
                ->visible(fn (ContentExposure $exposure): bool => $exposure->canVoid(InventoryAction::actor()))
                ->schema([
                    Select::make('scope')
                        ->label('Phạm vi')
                        ->options([
                            'product' => 'Một Sản phẩm',
                            'all' => 'Cả kho — lộ cả khoá mã hoá lẫn dữ liệu (ADR 0001)',
                        ])
                        ->default('product')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),
                    Select::make('product_id')
                        ->label('Sản phẩm')
                        ->options(fn (): array => Product::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->code} · {$product->name}"])
                            ->all())
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('scope') !== self::SCOPE_ALL)
                        ->visible(fn (Get $get): bool => $get('scope') !== self::SCOPE_ALL)
                        ->live(),
                    Text::make(fn (Get $get): string => self::planLabel($get('scope'), $get('product_id'), app(ContentExposure::class))),
                    Textarea::make('note')
                        ->label('Lý do coi là đã lộ')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (Action $action, ContentExposure $exposure, array $data): void {
                    $product = self::chosenProduct($data['scope'] ?? null, $data['product_id'] ?? null);
                    $tally = InventoryAction::attempt($action, fn () => $exposure->voidExposed(InventoryAction::actor(), $product, (string) $data['note']));

                    Notification::make()
                        ->success()
                        ->title("Đã Huỷ hàng {$tally->voidedUnits} Đơn vị hàng, {$tally->voidedSlots} Slot.")
                        ->body($tally->keptUnits === 0
                            ? null
                            : "{$tally->keptUnits} Đơn vị hàng đang có Slot Đã giữ nên bỏ lại; chạy lại sau khi hết hạn giữ.")
                        ->send();
                }),
        ];
    }

    /**
     * Bước hai của Ghi nhận giao bù: chọn đích danh Slot vừa tìm được, rồi chọn Phiếu xuất cũ hoặc
     * dựng phiếu mới.
     */
    public function recordLostDeliveryAction(): Action
    {
        $isNewDispatch = fn (Get $get): bool => blank($get('dispatch_id'));

        return Action::make('recordLostDelivery')
            ->modalHeading('Ghi nhận giao bù')
            ->modalDescription('Slot chọn ở đây chuyển thẳng sang Đã giao, không qua Thứ tự xuất — đây là ngoại lệ duy nhất. Làm được cả khi kho đang tạm dừng.')
            ->modalSubmitActionLabel('Ghi nhận')
            ->modalWidth(Width::TwoExtraLarge)
            ->schema(fn (array $arguments): array => [
                Text::make('Không Slot Còn hàng nào khớp Khoá chống trùng. Hàng đã giao rồi thì không ghi nhận lại được; hãy kiểm tra lại chuỗi khách gửi.')
                    ->visible(($arguments['candidates'] ?? []) === []),
                Radio::make('slot_id')
                    ->label('Slot đã giao cho khách')
                    ->options($arguments['candidates'] ?? [])
                    ->required()
                    ->visible(($arguments['candidates'] ?? []) !== []),
                Select::make('dispatch_id')
                    ->label('Phiếu xuất cũ')
                    ->helperText('Tìm theo mã đơn ngoài. Để trống thì dựng phiếu mới bên dưới.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Dispatch::query()
                        ->where('status', DispatchStatus::Completed)
                        ->where('external_ref', 'ilike', '%'.addcslashes($search, '\\%_').'%')
                        ->orderByDesc('id')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Dispatch $dispatch): array => [$dispatch->id => "#{$dispatch->id} · {$dispatch->external_ref}"])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Dispatch::query()->find($value)?->external_ref)
                    ->live(),
                Select::make('sales_channel_id')
                    ->label('Kênh bán')
                    ->options(fn (): array => SalesChannel::query()->usable()->orderBy('name')->pluck('name', 'id')->all())
                    ->markAsRequired()
                    ->visible($isNewDispatch),
                TextInput::make('external_ref')
                    ->label('Mã đơn ngoài')
                    ->maxLength(100)
                    ->helperText('Mã đơn của lần bán đã mất. Để trống thì tự sinh, nếu Kênh bán không bắt buộc.')
                    ->visible($isNewDispatch),
                Textarea::make('customer')
                    ->label('Khách')
                    ->rows(2)
                    ->visible($isNewDispatch),
                TextInput::make('sale_price')
                    ->label('Giá bán (tổng dòng)')
                    ->placeholder('Tuỳ chọn')
                    ->suffix('₫')
                    ->integer()
                    ->minValue(0),
                DateTimePicker::make('delivered_at')
                    ->label('Đã giao lúc')
                    ->seconds(false)
                    ->default(now())
                    ->maxDate(now())
                    ->required()
                    ->helperText('Mốc tính Hạn bảo hành: ghi đúng lúc khách nhận hàng, không phải lúc ghi nhận.'),
                Textarea::make('reason')
                    ->label('Lý do')
                    ->required()
                    ->rows(2)
                    ->helperText('Vì sao lần giao này phải ghi bằng tay, ví dụ "mất khi khôi phục backup ngày 17/09".'),
            ])
            ->action(function (Action $action, RecordedLostDelivery $recorded, array $data): void {
                $delivery = InventoryAction::attempt($action, fn () => $recorded->record(InventoryAction::actor(), new RecordedDeliveryDraft(
                    slot: Slot::query()->find($data['slot_id'] ?? null),
                    dispatch: filled($data['dispatch_id'] ?? null) ? Dispatch::query()->find($data['dispatch_id']) : null,
                    channel: filled($data['sales_channel_id'] ?? null) ? SalesChannel::query()->find($data['sales_channel_id']) : null,
                    externalRef: $data['external_ref'] ?? null,
                    customer: $data['customer'] ?? null,
                    salePrice: filled($data['sale_price'] ?? null) ? (int) $data['sale_price'] : null,
                    deliveredAt: filled($data['delivered_at'] ?? null) ? CarbonImmutable::parse($data['delivered_at']) : null,
                    reason: $data['reason'] ?? null,
                )));

                Notification::make()
                    ->success()
                    ->title('Đã ghi nhận lần giao.')
                    ->body("Phiếu xuất #{$delivery->dispatchLine->dispatch_id}, Slot #{$delivery->slot_id}.")
                    ->send();
            });
    }

    /**
     * Lần giao bị ảnh hưởng khi nội dung kho bị coi là đã lộ. Chỉ liệt kê để liên hệ khách: không nút
     * Báo lỗi hay Đổi hàng hàng loạt ở đây, đúng như spec.
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading('Lần giao bị ảnh hưởng nếu nội dung đã lộ')
            ->description('Lần giao Tài khoản còn Đã giao và còn trong Hạn bảo hành: khách vẫn đang dùng chính Tài khoản ấy. Mã dùng một lần không vào đây. Hệ thống không tự Báo lỗi hay Đổi hàng.')
            ->query(fn (): Builder => AffectedDelivery::exposedAccounts())
            ->recordUrl(fn (Delivery $record): string => DispatchResource::getUrl('view', ['record' => $record->dispatchLine->dispatch_id]))
            ->columns([
                TextColumn::make('dispatch')
                    ->label('Phiếu xuất')
                    ->state(fn (Delivery $record): string => $record->dispatchLine->dispatch->external_ref),
                TextColumn::make('channel')
                    ->label('Kênh bán')
                    ->state(fn (Delivery $record): string => $record->dispatchLine->dispatch->salesChannel->name),
                TextColumn::make('customer')
                    ->label('Khách')
                    ->state(fn (Delivery $record): ?string => $record->dispatchLine->dispatch->customer)
                    ->placeholder('Không có khách')
                    ->limit(40),
                TextColumn::make('product')
                    ->label('Sản phẩm')
                    ->state(fn (Delivery $record): string => $record->dispatchLine->product->name),
                TextColumn::make('unit')
                    ->label('Đơn vị hàng')
                    ->state(fn (Delivery $record): string => $record->unitLabel()),
                TextColumn::make('delivered_at')
                    ->label('Giao lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('warranty')
                    ->label('Hạn bảo hành')
                    ->state(fn (Delivery $record): string => $record->warrantyEndsOn()->format('d/m/Y')),
            ])
            ->filters([
                SelectFilter::make('product')
                    ->label('Sản phẩm')
                    ->options(fn (): array => Product::query()
                        ->where('type', ProductType::Account)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $ofProduct, mixed $productId) => $ofProduct->whereHas(
                            'stockUnit',
                            fn (Builder $units) => $units->where('stock_units.product_id', $productId),
                        ),
                    )),
            ]);
    }

    /**
     * Sản phẩm của phạm vi đang chọn; null là cả kho.
     */
    private static function chosenProduct(mixed $scope, mixed $productId): ?Product
    {
        return $scope === self::SCOPE_ALL ? null : Product::query()->findOrFail($productId);
    }

    /**
     * Xem trước số lượng sẽ huỷ, để Quản trị biết mình sắp đụng vào bao nhiêu hàng — nhất là ở phạm
     * vi cả kho, nơi con số là toàn bộ hàng còn bán được.
     */
    private static function planLabel(mixed $scope, mixed $productId, ContentExposure $exposure): string
    {
        if ($scope !== self::SCOPE_ALL && blank($productId)) {
            return 'Chọn Sản phẩm để xem sẽ huỷ bao nhiêu.';
        }

        $tally = $exposure->plan(InventoryAction::actor(), self::chosenProduct($scope, $productId));

        return sprintf(
            'Sẽ huỷ %d Đơn vị hàng (%d Slot Còn hàng)%s.',
            $tally->voidedUnits,
            $tally->voidedSlots,
            $tally->keptUnits === 0 ? '' : sprintf('; bỏ lại %d Đơn vị hàng đang có Slot Đã giữ', $tally->keptUnits),
        );
    }

    /**
     * @return list<Textarea>
     */
    private static function reasonSchema(string $label): array
    {
        return [
            Textarea::make('reason')
                ->label($label)
                ->required()
                ->rows(2),
        ];
    }
}
