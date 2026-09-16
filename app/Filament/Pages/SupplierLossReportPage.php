<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Reports\ProfitReportFilter;
use App\Inventory\Reports\ReportFormat;
use App\Inventory\Reports\SupplierLossReport;
use App\Inventory\Reports\SupplierLossRow;
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

/**
 * Báo cáo lỗ theo Nhà cung cấp. Adapter mỏng trên SupplierLossReport, cùng kiểu với
 * {@see ProfitReportPage}.
 */
class SupplierLossReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Lỗ theo nhà cung cấp';

    protected static ?string $title = 'Lỗ theo nhà cung cấp';

    protected static ?string $slug = 'bao-cao-lo-nha-cung-cap';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return app(SupplierLossReport::class)->canView(InventoryAction::actor());
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
                TextColumn::make('supplier')->label('Nhà cung cấp'),
                $money('defective_loss', 'Giá vốn hàng lỗi')
                    ->tooltip('Slot còn trong kho mất đi khi Đơn vị hàng của họ chuyển Lỗi'),
                $money('replacement_cost', 'Chi phí đổi hàng'),
                $money('reimbursement', 'Đã bồi hoàn tiền')
                    ->tooltip('Theo ngày giải quyết Khiếu nại nhà cung cấp'),
                TextColumn::make('replacement_goods_units')
                    ->label('Bồi hoàn bằng hàng (Đơn vị hàng)')
                    ->numeric(locale: 'vi')
                    ->alignEnd()
                    ->tooltip('Cột tham khảo: hàng thay thế vào kho với Giá vốn 0 nên không trừ vào Lỗ ròng'),
                $money('net_loss', 'Lỗ ròng'),
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
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Không có Nhà cung cấp nào phát sinh lỗ trong khoảng đã chọn.');
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (ReportFormat $format): Action => Action::make('export'.ucfirst($format->value))
            ->label("Xuất {$format->label()}")
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Action $action, SupplierLossReport $report) use ($format): StreamedResponse {
                $export = InventoryAction::attempt($action, fn () => $report->export(InventoryAction::actor(), $this->reportFilter(), $format));

                return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
            }), ReportFormat::cases());
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function records(): Collection
    {
        $report = app(SupplierLossReport::class);
        $columns = array_keys($report->columns());

        /** @var Collection<string, array<string, mixed>> $records */
        $records = collect($report->rows(InventoryAction::actor(), $this->reportFilter()))
            ->mapWithKeys(fn (SupplierLossRow $row): array => [
                $row->key() => array_combine($columns, array_map($row->cell(...), $columns)),
            ]);

        return $records;
    }

    private function reportFilter(): ProfitReportFilter
    {
        return ProfitReportFilter::fromTableFilters($this->tableFilters ?? []);
    }
}
