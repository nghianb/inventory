<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Reports\DispatchProfitReport;
use App\Inventory\Reports\DispatchProfitRow;
use App\Inventory\Reports\ProfitReportFilter;
use App\Inventory\Reports\ReportFormat;
use App\Models\SalesChannel;
use App\Models\Supplier;
use BackedEnum;
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
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Chi tiết Lãi/lỗ theo Phiếu xuất. Adapter mỏng trên DispatchProfitReport, cùng kiểu với
 * {@see ProfitReportPage}.
 */
class DispatchProfitReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::BaoCao;

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Lãi/lỗ theo phiếu xuất';

    protected static ?string $title = 'Lãi/lỗ theo phiếu xuất';

    protected static ?string $slug = 'bao-cao-lai-lo-phieu-xuat';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return app(DispatchProfitReport::class)->canView(InventoryAction::actor());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $money = fn (string $column, string $label): TextColumn => TextColumn::make($column)
            ->label($label)
            ->alignEnd()
            ->formatStateUsing(fn (int|string $state): string => SupplierClaimResource::money((int) $state));

        return $table
            ->records(fn (): Collection => $this->records())
            ->paginated(false)
            ->columns([
                TextColumn::make('dispatch')->label('Phiếu xuất'),
                TextColumn::make('external_ref')->label('Mã đơn ngoài'),
                TextColumn::make('channel')->label('Kênh bán'),
                $money('sale_price', 'Giá bán'),
                $money('cogs', 'Giá vốn'),
                $money('gross_profit', 'Lãi gộp'),
                $money('replacement_cost', 'Chi phí đổi hàng phát sinh')
                    ->tooltip('Cột tham khảo: Chi phí đổi hàng không trừ vào Lãi gộp của phiếu, mà trừ vào Lãi ròng kho'),
            ])
            ->filters([
                Filter::make('range')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Từ ngày')
                            ->default(fn (): string => ProfitReportFilter::currentMonth()->from->toDateString()),
                        DatePicker::make('to')
                            ->label('Đến ngày')
                            ->default(fn (): string => ProfitReportFilter::currentMonth()->to->toDateString()),
                    ])
                    ->indicateUsing(fn (): string => 'Từ '.$this->reportFilter()->from->format('d/m/Y').' đến '.$this->reportFilter()->to->format('d/m/Y')),
                SelectFilter::make('supplier')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('channel')
                    ->label('Kênh bán')
                    ->options(fn (): array => SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all()),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Không có Phiếu xuất nào giao hàng đã ghi Giá bán trong khoảng đã chọn.');
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (ReportFormat $format): Action => Action::make('export'.ucfirst($format->value))
            ->label("Xuất {$format->label()}")
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Action $action, DispatchProfitReport $report) use ($format): StreamedResponse {
                $export = InventoryAction::attempt($action, fn () => $report->export(InventoryAction::actor(), $this->reportFilter(), $format));

                return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
            }), ReportFormat::cases());
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function records(): Collection
    {
        $report = app(DispatchProfitReport::class);
        $columns = array_keys($report->columns());

        /** @var Collection<string, array<string, mixed>> $records */
        $records = collect($report->rows(InventoryAction::actor(), $this->reportFilter()))
            ->mapWithKeys(fn (DispatchProfitRow $row): array => [
                $row->key() => array_combine($columns, array_map($row->cell(...), $columns)),
            ]);

        return $records;
    }

    private function reportFilter(): ProfitReportFilter
    {
        return ProfitReportFilter::fromTableFilters($this->tableFilters ?? []);
    }
}
