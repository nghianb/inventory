<?php

namespace App\Filament\Pages;

use App\Filament\Support\InventoryAction;
use App\Inventory\Reports\DefectRateReport;
use App\Inventory\Reports\DefectRateReportFilter;
use App\Inventory\Reports\DefectRateReportRow;
use App\Inventory\Reports\ReportFormat;
use App\Models\Product;
use App\Models\Supplier;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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
 * Báo cáo Tỉ lệ lỗi theo Nhà cung cấp. Adapter mỏng: số liệu, quyền và cột nằm ở DefectRateReport;
 * bộ lọc của bảng chỉ giữ trạng thái để dựng DefectRateReportFilter. Bảng không chạy trên truy vấn
 * Eloquent mà trên các dòng của báo cáo, vì mỗi dòng là một Nhà cung cấp × Sản phẩm và sau mỗi Nhà
 * cung cấp còn có dòng tổng; cũng vì thế bảng không phân trang và không sắp xếp lại được: đổi thứ tự
 * thì dòng tổng rời khỏi khối của nó. Bộ lọc nằm trên URL để chia sẻ được một kỳ báo cáo.
 */
class DefectRateReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Báo cáo tỉ lệ lỗi';

    protected static ?string $title = 'Báo cáo tỉ lệ lỗi theo nhà cung cấp';

    protected static ?string $slug = 'bao-cao-ti-le-loi';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return app(DefectRateReport::class)->canView(InventoryAction::actor());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $count = fn (string $column, string $label): TextColumn => TextColumn::make($column)
            ->label($label)
            ->numeric(locale: 'vi')
            ->alignEnd();

        // Dòng tổng của Nhà cung cấp in đậm cả dòng, để không lẫn với dòng Sản phẩm ngay trên nó.
        $total = fn (TextColumn $column): TextColumn => $column
            ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null);

        return $table
            ->records(fn (): Collection => $this->records())
            ->paginated(false)
            ->columns(array_map($total, [
                TextColumn::make('supplier')->label('Nhà cung cấp'),
                TextColumn::make('code')->label('Mã sản phẩm'),
                TextColumn::make('name')->label('Sản phẩm'),
                $count('intake_units', 'Đơn vị hàng nhập')
                    ->tooltip('Đơn vị hàng có Lô nhập xác nhận trong khoảng; hàng Huỷ nhập không tính'),
                $count('delivered_units', 'Đã giao')
                    ->tooltip('Đơn vị hàng đã giao ít nhất một Slot: mẫu số của Tỉ lệ lỗi'),
                $count('defective_units', 'Đơn vị hàng Lỗi')
                    ->tooltip('Trong số đã giao, Đơn vị hàng đang Lỗi: tử số của Tỉ lệ lỗi'),
                TextColumn::make('defect_rate')
                    ->label('Tỉ lệ lỗi')
                    ->alignEnd()
                    ->placeholder('Chưa giao'),
                $count('defective_in_stock_units', 'Lỗi trong kho')
                    ->tooltip('Đơn vị hàng Lỗi chưa giao Slot nào; tham khảo, không vào Tỉ lệ lỗi'),
                $count('rejected_lines', 'Dòng lỗi/trùng khi nhập')
                    ->tooltip('Chất lượng file Nhà cung cấp gửi: dòng bị bỏ chưa từng thành hàng nên không vào Tỉ lệ lỗi'),
            ]))
            ->filters([
                Filter::make('range')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Nhập từ ngày')
                            ->default(fn (): string => DefectRateReportFilter::currentMonth()->from->toDateString()),
                        DatePicker::make('to')
                            ->label('Đến ngày')
                            ->default(fn (): string => DefectRateReportFilter::currentMonth()->to->toDateString()),
                    ])
                    ->indicateUsing(fn (): string => 'Nhập từ '.$this->reportFilter()->from->format('d/m/Y').' đến '.$this->reportFilter()->to->format('d/m/Y')),
                SelectFilter::make('suppliers')
                    ->label('Nhà cung cấp')
                    ->multiple()
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('products')
                    ->label('Sản phẩm')
                    ->multiple()
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all()),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Không có Nhà cung cấp nào nhập hàng trong khoảng đã chọn.');
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (ReportFormat $format): Action => Action::make('export'.ucfirst($format->value))
            ->label("Xuất {$format->label()}")
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Action $action, DefectRateReport $report) use ($format): StreamedResponse {
                $export = InventoryAction::attempt($action, fn () => $report->export(InventoryAction::actor(), $this->reportFilter(), $format));

                return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
            }), ReportFormat::cases());
    }

    /**
     * Các dòng báo cáo dưới dạng bản ghi của bảng, khoá là khoá dòng của báo cáo. Dòng tổng của một
     * Nhà cung cấp để trống Sản phẩm và ghi 'Tổng' ở cột Mã sản phẩm.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function records(): Collection
    {
        /** @var Collection<string, array<string, mixed>> $records */
        $records = collect(app(DefectRateReport::class)->rows(InventoryAction::actor(), $this->reportFilter()))
            ->mapWithKeys(fn (DefectRateReportRow $row): array => [$row->key() => [
                'supplier' => $row->supplierName,
                'code' => $row->cell('code'),
                'name' => $row->name,
                'intake_units' => $row->intakeUnits,
                'delivered_units' => $row->deliveredUnits,
                'defective_units' => $row->defectiveUnits,
                'defect_rate' => DefectRateReportRow::percentage($row->defectRate()),
                'defective_in_stock_units' => $row->defectiveInStockUnits,
                'rejected_lines' => $row->rejectedLines,
                'is_total' => $row->isTotal(),
            ]]);

        return $records;
    }

    /**
     * Bộ lọc báo cáo theo trạng thái bộ lọc của bảng.
     */
    private function reportFilter(): DefectRateReportFilter
    {
        $filters = $this->tableFilters ?? [];
        $default = DefectRateReportFilter::currentMonth();
        $day = fn (mixed $value, CarbonImmutable $fallback): CarbonImmutable => is_string($value) && $value !== ''
            ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value)->startOfDay(), $fallback, report: false)
            : $fallback;
        $ids = fn (string $filter): array => array_values(array_map(intval(...), array_filter((array) ($filters[$filter]['values'] ?? []), is_numeric(...))));

        return new DefectRateReportFilter(
            from: $day($filters['range']['from'] ?? null, $default->from),
            to: $day($filters['range']['to'] ?? null, $default->to),
            supplierIds: $ids('suppliers'),
            productIds: $ids('products'),
        );
    }
}
