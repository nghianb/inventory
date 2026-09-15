<?php

namespace App\Filament\Resources\Dispatches;

use App\Filament\Resources\Dispatches\Pages\CreateDispatch;
use App\Filament\Resources\Dispatches\Pages\DispatchResult;
use App\Filament\Resources\Dispatches\Pages\ListDispatches;
use App\Filament\Resources\Dispatches\Pages\ViewDispatch;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchStatus;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\SalesChannel;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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

        return $schema->components([
            Callout::make('Phiếu xuất chưa hợp lệ')
                ->danger()
                ->description(fn (mixed $livewire): ?HtmlString => $livewire instanceof CreateDispatch ? self::problemList($livewire->problems) : null)
                ->visible(fn (mixed $livewire): bool => $livewire instanceof CreateDispatch && $livewire->problems !== [])
                ->columnSpanFull(),
            Section::make('Thông tin đơn')
                ->columns(2)
                ->schema([
                    Select::make('sales_channel_id')
                        ->label('Kênh bán')
                        ->options(fn (): array => SalesChannel::query()->usable()->orderBy('name')->pluck('name', 'id')->all())
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
                ->description('Slot được chọn tự động theo Thứ tự xuất.')
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
            RepeatableEntry::make('delivery_rows')
                ->label('Lần giao')
                ->state(fn (Dispatch $record): array => $record->deliveries()
                    ->orderBy('deliveries.id')
                    ->with(['dispatchLine.product', 'slot', 'stockUnit.product.contentFields'])
                    ->get()
                    ->map(fn (Delivery $delivery): array => [
                        'product' => $delivery->dispatchLine->product->name,
                        'unit' => "#{$delivery->stock_unit_id} · Slot #{$delivery->slot_id}",
                        'content' => collect($delivery->stockUnit->maskedContent())
                            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                            ->implode(' · '),
                        'delivered_at' => $delivery->delivered_at->format('d/m/Y H:i'),
                        'warranty' => $delivery->warrantyEndsOn()->format('d/m/Y'),
                        'status' => $delivery->slot->status,
                    ])
                    ->all())
                ->table([
                    TableColumn::make('Sản phẩm'),
                    TableColumn::make('Đơn vị hàng'),
                    TableColumn::make('Nội dung (đã che)'),
                    TableColumn::make('Giao lúc'),
                    TableColumn::make('Hạn bảo hành'),
                    TableColumn::make('Trạng thái'),
                ])
                ->schema([
                    TextEntry::make('product'),
                    TextEntry::make('unit'),
                    TextEntry::make('content'),
                    TextEntry::make('delivered_at'),
                    TextEntry::make('warranty'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (SlotStatus $state): string => $state->label())
                        ->color(fn (SlotStatus $state): string => $state === SlotStatus::Delivered ? 'success' : 'gray'),
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
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
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
