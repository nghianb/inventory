<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Reports\ReportFormat;
use App\Inventory\Reports\StockReport;
use App\Inventory\Reports\StockReportFilter;
use App\Models\Product;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Báo cáo Tồn kho. Adapter mỏng: số liệu, quyền và cột theo Vai trò nằm ở StockReport; bộ lọc của
 * bảng chỉ giữ trạng thái để dựng StockReportFilter (lọc theo Nhà cung cấp đổi cách đếm nên không
 * áp lên truy vấn sau khi đếm). Bộ lọc nằm trên URL để widget cảnh báo mở sẵn.
 */
class StockReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Báo cáo tồn kho';

    protected static ?string $title = 'Báo cáo tồn kho';

    protected static ?string $slug = 'bao-cao-ton-kho';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return app(StockReport::class)->canView(InventoryAction::actor());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $report = app(StockReport::class);
        $values = $report->seesCost(InventoryAction::actor());
        $count = fn (string $column, string $label): TextColumn => TextColumn::make($column)
            ->label($label)
            ->numeric(locale: 'vi')
            ->alignEnd()
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy($column, $direction));
        $money = fn (string $column, string $label): TextColumn => $count($column, $label)
            ->formatStateUsing(fn (int|string $state): string => SupplierClaimResource::money((int) $state))
            ->visible($values);

        return $table
            ->query(fn (): Builder => $report->query(InventoryAction::actor(), $this->reportFilter()))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')
                    ->label('Mã sản phẩm')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('products.code', $direction)),
                TextColumn::make('name')
                    ->label('Sản phẩm')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('products.name', $direction)),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (Product $record): string => $record->isDiscontinued() ? 'Ngừng bán' : 'Đang bán')
                    ->color(fn (Product $record): string => $record->isDiscontinued() ? 'gray' : 'success'),
                $count('sellable_slots', 'Tồn bán được')
                    ->badge()
                    ->color(fn (Product $record): string => $record->getAttribute('low_stock') ? 'warning' : 'gray')
                    ->tooltip(fn (Product $record): ?string => $record->getAttribute('low_stock') ? 'Sắp hết' : null),
                TextColumn::make('low_stock_threshold')
                    ->label('Ngưỡng sắp hết')
                    ->placeholder('Không cảnh báo')
                    ->alignEnd(),
                $count('reserved_slots', 'Đã giữ'),
                $count('paused_slots', 'Tạm ngừng')
                    ->tooltip('Báo lỗi Chờ xác minh'),
                $count('below_min_slots', 'Không đạt Hạn còn lại tối thiểu'),
                $count('defective_slots', 'Tồn lỗi'),
                $count('stock_unit_count', 'Đơn vị hàng'),
                $money('stock_value', 'Giá trị tồn'),
                $count('expiring_slots', 'Hết hạn')
                    ->label(fn (): string => "Hết hạn trong {$this->reportFilter()->expiringWithinDays} ngày"),
                $money('expiring_cost', 'Giá vốn sắp mất'),
            ])
            ->filters([
                SelectFilter::make('products')
                    ->label('Sản phẩm')
                    ->multiple()
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('supplier')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible($values)
                    ->query(fn (Builder $query): Builder => $query),
                Filter::make('low_stock')
                    ->label('Sắp hết')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query),
                Filter::make('expiring')
                    ->schema([
                        TextInput::make('days')
                            ->label('Hết hạn trong')
                            ->suffix('ngày')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(StockReportFilter::MAX_EXPIRING_DAYS)
                            ->default(StockReportFilter::DEFAULT_EXPIRING_DAYS),
                        Toggle::make('only')
                            ->label('Chỉ Sản phẩm có hàng hết hạn trong N ngày'),
                    ])
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(fn (array $data): ?string => ($data['only'] ?? false)
                        ? 'Hết hạn trong '.$this->reportFilter()->expiringWithinDays.' ngày'
                        : null),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Không có Sản phẩm nào khớp bộ lọc.');
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (ReportFormat $format): Action => Action::make('export'.ucfirst($format->value))
            ->label("Xuất {$format->label()}")
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Action $action, StockReport $report) use ($format): StreamedResponse {
                $export = InventoryAction::attempt($action, fn () => $report->export(InventoryAction::actor(), $this->reportFilter(), $format));

                return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
            }), ReportFormat::cases());
    }

    /**
     * Bộ lọc báo cáo theo trạng thái bộ lọc của bảng. Nhà cung cấp chỉ áp cho người thấy giá trị,
     * kể cả khi URL mang sẵn bộ lọc đó.
     */
    private function reportFilter(): StockReportFilter
    {
        $filters = $this->tableFilters ?? [];
        $days = $filters['expiring']['days'] ?? null;
        $supplier = $filters['supplier']['value'] ?? null;

        return new StockReportFilter(
            productIds: array_values(array_map(intval(...), array_filter((array) ($filters['products']['values'] ?? []), is_numeric(...)))),
            supplierId: is_numeric($supplier) && app(StockReport::class)->seesCost(InventoryAction::actor()) ? (int) $supplier : null,
            lowStockOnly: (bool) ($filters['low_stock']['isActive'] ?? false),
            expiringWithinDays: is_numeric($days) ? max(0, min(StockReportFilter::MAX_EXPIRING_DAYS, (int) $days)) : StockReportFilter::DEFAULT_EXPIRING_DAYS,
            expiringOnly: (bool) ($filters['expiring']['only'] ?? false),
        );
    }
}
