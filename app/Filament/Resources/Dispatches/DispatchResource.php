<?php

namespace App\Filament\Resources\Dispatches;

use App\Filament\Resources\Dispatches\Pages\CreateDispatch;
use App\Filament\Resources\Dispatches\Pages\DispatchResult;
use App\Filament\Resources\Dispatches\Pages\ListDispatches;
use App\Filament\Resources\Dispatches\Pages\ViewDispatch;
use App\Filament\Resources\Dispatches\Widgets\DispatchDeliveries;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Stock\SellableStock;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\DispatchRevision;
use App\Models\Product;
use App\Models\SalesChannel;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Phiếu xuất trong panel (phương án A của prototype): form Filament chuẩn → modal xác nhận →
 * màn kết quả → trang xem phiếu. Adapter mỏng: kiểm tra, tồn, tạo phiếu và nội dung đều gọi
 * module Kho (ManualDispatch, SellableStock, ContentReveal). Nhập kho không thấy.
 */
class DispatchResource extends Resource
{
    protected static ?string $model = Dispatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $modelLabel = 'phiếu xuất';

    protected static ?string $pluralModelLabel = 'Phiếu xuất';

    protected static ?string $navigationLabel = 'Phiếu xuất';

    protected static ?string $slug = 'phieu-xuat';

    protected static ?string $recordTitleAttribute = 'external_ref';

    public static function form(Schema $schema): Schema
    {
        $channel = fn (Get $get): ?SalesChannel => filled($get('sales_channel_id')) ? SalesChannel::query()->find($get('sales_channel_id')) : null;
        $product = fn (Get $get): ?Product => filled($get('product_id')) ? Product::query()->find($get('product_id')) : null;
        // Giao thêm: Thông tin đơn lấy từ phiếu cũ, chỉ đọc.
        $additional = fn (mixed $livewire): bool => $livewire instanceof CreateDispatch && $livewire->isAdditional();

        return $schema->components([
            Callout::make('Phiếu xuất chưa hợp lệ')
                ->danger()
                ->description(fn (mixed $livewire): ?HtmlString => $livewire instanceof CreateDispatch ? self::problemList($livewire->problems) : null)
                ->visible(fn (mixed $livewire): bool => $livewire instanceof CreateDispatch && $livewire->problems !== [])
                ->columnSpanFull(),
            Section::make('Thông tin đơn')
                ->description(fn (mixed $livewire): ?string => $additional($livewire) ? 'Giao thêm vào phiếu này; không sửa được ở đây.' : null)
                ->disabled($additional)
                ->columns(2)
                ->schema([
                    Select::make('sales_channel_id')
                        ->label('Kênh bán')
                        ->options(fn (mixed $livewire): array => SalesChannel::query()
                            ->when(! $additional($livewire), fn (Builder $query) => $query->usable())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->markAsRequired()
                        ->live(),
                    TextInput::make('external_ref')
                        ->label('Mã đơn ngoài')
                        ->maxLength(100)
                        ->markAsRequired(fn (Get $get): bool => (bool) $channel($get)?->requires_external_ref)
                        ->placeholder(fn (Get $get): ?string => $channel($get)?->requires_external_ref === false ? 'Để trống để tự sinh' : null)
                        ->helperText(fn (Get $get): ?string => match ($channel($get)?->requires_external_ref) {
                            true => 'Kênh bán này bắt buộc mã đơn ngoài.',
                            false => 'Không bắt buộc; để trống thì tự sinh mã PX-YYYYMMDD-NNNN.',
                            null => null,
                        }),
                    Textarea::make('customer')
                        ->label('Khách')
                        ->helperText('Tên, SĐT, link chat… Tìm kiếm được khi bảo hành.')
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('note')
                        ->label('Ghi chú')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),
            Section::make('Dòng xuất')
                ->description(fn (mixed $livewire): string => $additional($livewire)
                    ? 'Dòng xuất mới có Loại Giao thêm; Slot được chọn tự động theo Thứ tự xuất.'
                    : 'Slot được chọn tự động theo Thứ tự xuất.')
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->addActionLabel('Thêm dòng')
                        ->defaultItems(1)
                        ->reorderable(false)
                        ->columns(12)
                        ->schema([
                            Select::make('product_id')
                                ->label('Sản phẩm')
                                ->options(fn (): array => Product::query()
                                    ->onSale()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->code} · {$product->name}"])
                                    ->all())
                                ->searchable()
                                ->markAsRequired()
                                ->live()
                                ->hint(fn (Get $get): ?HtmlString => ($selected = $product($get)) === null ? null : self::stockBadge($selected))
                                ->columnSpan(['default' => 12, 'md' => 6]),
                            TextInput::make('quantity')
                                ->label('Số lượng')
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true)
                                ->columnSpan(['default' => 4, 'md' => 2]),
                            TextInput::make('sale_price')
                                ->label('Giá bán (tổng dòng)')
                                ->placeholder('Tuỳ chọn')
                                ->suffix('₫')
                                ->integer()
                                ->minValue(0)
                                ->live(onBlur: true)
                                ->columnSpan(['default' => 8, 'md' => 4]),
                        ]),
                    Text::make(fn (Get $get): string => self::totals((array) ($get('lines') ?? []))),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Phiếu xuất')
                ->columns(3)
                ->schema([
                    TextEntry::make('salesChannel.name')->label('Kênh bán'),
                    TextEntry::make('external_ref')->label('Mã đơn ngoài')->copyable(),
                    TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn (DispatchStatus $state): string => $state->label())
                        ->color(fn (DispatchStatus $state): string => $state->color()),
                    TextEntry::make('customer')->label('Khách')->placeholder('Không có')->columnSpan(2),
                    TextEntry::make('total_sale_price')
                        ->label('Tổng Giá bán')
                        ->state(fn (Dispatch $record): ?string => self::money($record->totalSalePrice()))
                        ->placeholder('Chưa có Giá bán'),
                    TextEntry::make('note')->label('Ghi chú')->placeholder('Không có')->columnSpan(2),
                    TextEntry::make('creator.name')->label('Người tạo'),
                    TextEntry::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i'),
                ]),
            RepeatableEntry::make('line_rows')
                ->label('Dòng xuất')
                ->state(fn (Dispatch $record): array => $record->lines()->with('product')->get()->map(fn (DispatchLine $line): array => [
                    'product' => $line->product->name,
                    'kind' => $line->kind->label(),
                    'quantity' => $line->quantity,
                    'sale_price' => self::money($line->sale_price),
                ])->all())
                ->table([
                    TableColumn::make('Sản phẩm'),
                    TableColumn::make('Loại'),
                    TableColumn::make('Số lượng'),
                    TableColumn::make('Giá bán'),
                ])
                ->schema([
                    TextEntry::make('product'),
                    TextEntry::make('kind')->badge(),
                    TextEntry::make('quantity'),
                    TextEntry::make('sale_price')->placeholder('Chưa có'),
                ])
                ->columnSpanFull(),
            Livewire::make(DispatchDeliveries::class, fn (Dispatch $record): array => ['record' => $record])
                ->key('deliveries')
                ->columnSpanFull(),
            Section::make('Lịch sử sửa phiếu')
                ->description('Ai sửa, khi nào, giá trị cũ → mới. Tách khỏi Sổ biến động kho.')
                ->schema([
                    RepeatableEntry::make('revision_rows')
                        ->hiddenLabel()
                        ->state(fn (Dispatch $record): array => $record->revisions()->with(['actor', 'dispatchLine.product'])->get()->map(fn (DispatchRevision $revision): array => [
                            'occurred_at' => $revision->occurred_at->format('d/m/Y H:i'),
                            'actor' => $revision->actor->name,
                            'field' => $revision->label(),
                            'old' => $revision->oldValueLabel(),
                            'new' => $revision->newValueLabel(),
                        ])->all())
                        ->placeholder('Chưa sửa lần nào.')
                        ->table([
                            TableColumn::make('Khi nào'),
                            TableColumn::make('Ai'),
                            TableColumn::make('Trường'),
                            TableColumn::make('Cũ'),
                            TableColumn::make('Mới'),
                        ])
                        ->schema([
                            TextEntry::make('occurred_at'),
                            TextEntry::make('actor'),
                            TextEntry::make('field'),
                            TextEntry::make('old')->placeholder('Trống'),
                            TextEntry::make('new')->placeholder('Trống'),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['salesChannel', 'creator'])->withCount('deliveries'))
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('external_ref')
                    ->label('Mã đơn ngoài')
                    ->searchable(),
                TextColumn::make('salesChannel.name')
                    ->label('Kênh bán'),
                TextColumn::make('customer')
                    ->label('Khách')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (DispatchStatus $state): string => $state->label())
                    ->color(fn (DispatchStatus $state): string => $state->color()),
                TextColumn::make('deliveries_count')
                    ->label('Slot'),
                TextColumn::make('creator.name')
                    ->label('Người tạo'),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('sales_channel_id')
                    ->label('Kênh bán')
                    ->relationship('salesChannel', 'name'),
                SelectFilter::make('created_by')
                    ->label('Người tạo')
                    ->relationship('creator', 'name'),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Tạo từ ngày'),
                        DatePicker::make('until')->label('Tạo đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('created_at', '>=', CarbonImmutable::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->where('created_at', '<', CarbonImmutable::parse($date)->addDay()->startOfDay()))),
                Filter::make('delivered_content')
                    ->schema([
                        TextInput::make('term')
                            ->label('Trường không nhạy cảm')
                            ->helperText('Ví dụ Serial thẻ nạp, tên đăng nhập Tài khoản. Trường nhạy cảm không tìm được; mã hãy tìm theo Khoá chống trùng.'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['term'] ?? null)
                        ? $query->whereDeliveredContent((string) $data['term'])
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Đăng ký widget thành component Livewire; bảng Lần giao nhúng vào infolist bằng Livewire::make
     * vẫn cần đăng ký để các request sau của nó (Xem mã) tìm được class.
     */
    public static function getWidgets(): array
    {
        return [
            DispatchDeliveries::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDispatches::route('/'),
            'create' => CreateDispatch::route('/tao'),
            'result' => DispatchResult::route('/{record}/ket-qua'),
            'view' => ViewDispatch::route('/{record}'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function draftFromForm(array $data): DispatchDraft
    {
        $lines = array_values((array) ($data['lines'] ?? []));
        $products = Product::query()
            ->whereIn('id', array_filter(array_map(fn (array $line): mixed => $line['product_id'] ?? null, $lines)))
            ->get()
            ->keyBy('id');

        return new DispatchDraft(
            channel: filled($data['sales_channel_id'] ?? null) ? SalesChannel::query()->find($data['sales_channel_id']) : null,
            externalRef: $data['external_ref'] ?? null,
            lines: array_map(fn (array $line): DispatchLineDraft => new DispatchLineDraft(
                product: filled($line['product_id'] ?? null) ? $products->get($line['product_id']) : null,
                quantity: (int) ($line['quantity'] ?? 0),
                salePrice: filled($line['sale_price'] ?? null) ? (int) $line['sale_price'] : null,
            ), $lines),
            customer: $data['customer'] ?? null,
            note: $data['note'] ?? null,
        );
    }

    /**
     * Thanh tổng cuối form: số dòng · số Slot · tổng Giá bán.
     *
     * @param  array<mixed>  $lines
     */
    public static function totals(array $lines): string
    {
        $slots = 0;
        $prices = [];

        foreach ($lines as $line) {
            $slots += max(0, (int) ($line['quantity'] ?? 0));

            if (is_array($line) && filled($line['sale_price'] ?? null)) {
                $prices[] = (int) $line['sale_price'];
            }
        }

        return sprintf(
            '%s dòng · %s Slot · Tổng Giá bán: %s',
            self::count(count($lines)),
            self::count($slots),
            self::money($prices === [] ? null : array_sum($prices)) ?? 'chưa có',
        );
    }

    /**
     * @param  list<array{message: string, url: ?string}>  $problems
     */
    private static function problemList(array $problems): HtmlString
    {
        return new HtmlString(implode('<br>', array_map(fn (array $problem): string => e($problem['message']).($problem['url'] === null
            ? ''
            : ' <a href="'.e($problem['url']).'" style="text-decoration: underline">Mở Phiếu xuất cũ</a>'), $problems)));
    }

    /**
     * Badge Tồn bán được của Sản phẩm: xanh, vàng khi không vượt Ngưỡng sắp hết, đỏ khi hết.
     */
    private static function stockBadge(Product $product): HtmlString
    {
        $stock = app(SellableStock::class);

        return new HtmlString(Blade::render(
            '<x-filament::badge :color="$color">Tồn bán được: {{ $count }}</x-filament::badge>',
            ['color' => $stock->level($product)->color(), 'count' => self::count($stock->count($product))],
        ));
    }

    public static function money(?int $amount): ?string
    {
        return $amount === null ? null : number_format($amount, 0, ',', '.').' ₫';
    }

    public static function count(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }
}
