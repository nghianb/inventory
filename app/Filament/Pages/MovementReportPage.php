<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Reports\MovementReport;
use App\Inventory\Reports\MovementReportFilter;
use App\Inventory\Reports\ReportFormat;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Supplier;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * Báo cáo Nhập/xuất. Adapter mỏng: số liệu, quyền và cột theo Vai trò nằm ở MovementReport; bộ lọc
 * của bảng chỉ giữ trạng thái để dựng MovementReportFilter (các bộ lọc đổi cách đếm nên không áp
 * lên truy vấn sau khi đếm). Bộ lọc nằm trên URL để chia sẻ được một kỳ báo cáo.
 */
class MovementReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Báo cáo nhập/xuất';

    protected static ?string $title = 'Báo cáo nhập/xuất';

    protected static ?string $slug = 'bao-cao-nhap-xuat';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return app(MovementReport::class)->canView(InventoryAction::actor());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $report = app(MovementReport::class);
        $values = $report->seesValues(InventoryAction::actor());
        // Cột nào hiện là việc của MovementReport::columns() (Vai trò và bộ lọc); panel chỉ hỏi.
        $shows = fn (string $column): bool => array_key_exists($column, $report->columns(InventoryAction::actor(), $this->reportFilter()));
        $count = fn (string $column, string $label): TextColumn => TextColumn::make($column)
            ->label($label)
            ->numeric(locale: 'vi')
            ->alignEnd()
            ->visible(fn (): bool => $shows($column))
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy($column, $direction));
        $money = fn (string $column, string $label): TextColumn => $count($column, $label)
            ->formatStateUsing(fn (int|string $state): string => SupplierClaimResource::money((int) $state));

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
                $count('in_slots', 'Nhập')
                    ->tooltip('Theo ngày xác nhận Lô nhập; hàng Huỷ nhập không tính'),
                $count('in_replacement_slots', 'Trong đó hàng thay thế')
                    ->tooltip('Hàng thay thế từ Khiếu nại nhà cung cấp, Giá vốn 0'),
                $money('in_cost', 'Giá vốn nhập'),
                $count('sold_slots', 'Giao bán')
                    ->tooltip('Gồm cả Giao thêm; lần giao đã bị Giao thay không tính'),
                $count('replacement_slots', 'Đổi hàng'),
                $count('corrective_slots', 'Giao thay'),
                $money('out_cost', 'Giá vốn xuất'),
                $money('sale_total', 'Giá bán'),
                $count('voided_slots', 'Huỷ hàng'),
                $count('defective_slots', 'Chuyển Tồn lỗi')
                    ->tooltip('Slot còn trong kho thành Tồn lỗi; Slot đã giao không tính'),
            ])
            ->filters([
                Filter::make('range')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Từ ngày')
                            ->default(fn (): string => MovementReportFilter::currentMonth()->from->toDateString()),
                        DatePicker::make('to')
                            ->label('Đến ngày')
                            ->default(fn (): string => MovementReportFilter::currentMonth()->to->toDateString()),
                    ])
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(fn (): string => 'Từ '.$this->reportFilter()->from->format('d/m/Y').' đến '.$this->reportFilter()->to->format('d/m/Y')),
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
                SelectFilter::make('channel')
                    ->label('Kênh bán')
                    ->options(fn (): array => SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query): Builder => $query),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Không có Sản phẩm nào biến động trong khoảng đã chọn.');
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (ReportFormat $format): Action => Action::make('export'.ucfirst($format->value))
            ->label("Xuất {$format->label()}")
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Action $action, MovementReport $report) use ($format): StreamedResponse {
                $export = InventoryAction::attempt($action, fn () => $report->export(InventoryAction::actor(), $this->reportFilter(), $format));

                return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
            }), ReportFormat::cases());
    }

    /**
     * Bộ lọc báo cáo theo trạng thái bộ lọc của bảng. Nhà cung cấp chỉ áp cho người thấy giá trị,
     * kể cả khi URL mang sẵn bộ lọc đó.
     */
    private function reportFilter(): MovementReportFilter
    {
        $filters = $this->tableFilters ?? [];
        $default = MovementReportFilter::currentMonth();
        $day = fn (mixed $value, CarbonImmutable $fallback): CarbonImmutable => is_string($value) && $value !== ''
            ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value)->startOfDay(), $fallback, report: false)
            : $fallback;
        $supplier = $filters['supplier']['value'] ?? null;
        $channel = $filters['channel']['value'] ?? null;

        return new MovementReportFilter(
            from: $day($filters['range']['from'] ?? null, $default->from),
            to: $day($filters['range']['to'] ?? null, $default->to),
            productIds: array_values(array_map(intval(...), array_filter((array) ($filters['products']['values'] ?? []), is_numeric(...)))),
            supplierId: is_numeric($supplier) && app(MovementReport::class)->seesValues(InventoryAction::actor()) ? (int) $supplier : null,
            salesChannelId: is_numeric($channel) ? (int) $channel : null,
        );
    }
}
