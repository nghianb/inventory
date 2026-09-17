<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Filament\Support\NavGroup;
use App\Inventory\Reports\ProfitReport;
use App\Inventory\Reports\ProfitReportFilter;
use App\Inventory\Reports\ProfitReportRow;
use App\Inventory\Reports\ReportFormat;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Supplier;
use BackedEnum;
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
use UnitEnum;

/**
 * Báo cáo Lãi/lỗ theo Sản phẩm. Adapter mỏng: số liệu, quyền và cột nào hiện nằm ở ProfitReport; giá
 * trị luôn lấy qua ProfitReportRow::cell() theo đúng khoá cột của báo cáo, để màn hình và file xuất
 * không lệch nhau. Bộ lọc của bảng chỉ giữ trạng thái để dựng ProfitReportFilter và nằm trên URL để
 * chia sẻ được một kỳ báo cáo.
 *
 * Bảng chạy trên các dòng của báo cáo chứ không phải truy vấn Eloquent, vì sau các dòng Sản phẩm còn
 * có dòng tổng và dòng 'Chưa có Giá bán'; cũng vì thế bảng không phân trang và không sắp xếp lại
 * được: đổi thứ tự thì hai dòng ấy rời khỏi chỗ của chúng.
 */
class ProfitReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::BaoCao;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Lãi/lỗ';

    protected static ?string $title = 'Báo cáo lãi/lỗ';

    protected static ?string $slug = 'bao-cao-lai-lo';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return app(ProfitReport::class)->canView(InventoryAction::actor());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $report = app(ProfitReport::class);
        // Cột nào hiện là việc của ProfitReport::columns() (bộ lọc quyết định); panel chỉ hỏi. Phải
        // hỏi lại mỗi lần gọi chứ không giữ sẵn một mảng: bảng được dựng trước khi người dùng đổi bộ
        // lọc, nên mảng giữ sẵn mãi là của bộ lọc cũ và cột đáng lẽ phải ẩn vẫn hiện.
        $shows = fn (string $column): bool => array_key_exists($column, $report->columns($this->reportFilter()));

        return $table
            ->records(fn (): Collection => $this->records())
            ->paginated(false)
            ->columns([
                TextColumn::make('code')
                    ->label('Mã sản phẩm')
                    // Dòng 'Chưa có Giá bán' dẫn thẳng sang danh sách Phiếu xuất để đi tìm và điền giá.
                    ->url(fn (array $record): ?string => $record['is_unpriced'] ? DispatchResource::getUrl('index') : null)
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                TextColumn::make('name')
                    ->label('Sản phẩm')
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                $this->money('revenue', 'Doanh thu'),
                $this->money('cogs', 'Giá vốn hàng bán')
                    ->tooltip('Giá vốn các Slot khách thực nhận; Slot giao nhầm đã Huỷ hàng không tính'),
                $this->money('gross_profit', 'Lãi gộp'),
                TextColumn::make('margin')
                    ->label('% biên')
                    ->alignEnd()
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                $this->money('replacement_cost', 'Chi phí đổi hàng')
                    ->visible(fn (): bool => $shows('replacement_cost'))
                    ->tooltip('Giá vốn Slot giao ra khi Đổi hàng, gắn với Sản phẩm của Đơn vị hàng lỗi'),
                $this->money('defective_loss', 'Tổn thất hàng Lỗi')
                    ->visible(fn (): bool => $shows('defective_loss'))
                    ->tooltip('Slot còn trong kho mất đi khi Đơn vị hàng chuyển Lỗi; Khôi phục thì bỏ'),
                $this->money('wrong_delivery_loss', 'Tổn thất giao nhầm')
                    ->visible(fn (): bool => $shows('wrong_delivery_loss')),
                $this->money('content_exposed_loss', 'Tổn thất lộ nội dung')
                    ->visible(fn (): bool => $shows('content_exposed_loss')),
                $this->money('discontinued_lot_loss', 'Tổn thất ngừng kinh doanh lô')
                    ->visible(fn (): bool => $shows('discontinued_lot_loss')),
                $this->money('expiry_loss', 'Tổn thất hết hạn')
                    ->visible(fn (): bool => $shows('expiry_loss'))
                    ->tooltip('Slot còn trong kho quá Hạn sử dụng, tính vào ngày hết hạn'),
                $this->money('reimbursement', 'Bồi hoàn tiền')
                    ->visible(fn (): bool => $shows('reimbursement'))
                    ->tooltip('Theo ngày giải quyết Khiếu nại nhà cung cấp; hàng thay thế không tính ở đây'),
                $this->money('adjustments', 'Điều chỉnh')
                    ->visible(fn (): bool => $shows('adjustments')),
                $this->money('net_profit', 'Lãi ròng kho')
                    ->visible(fn (): bool => $shows('net_profit'))
                    ->tooltip('Lãi gộp trừ Điều chỉnh'),
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
                SelectFilter::make('products')
                    ->label('Sản phẩm')
                    ->multiple()
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('supplier')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('channel')
                    ->label('Kênh bán')
                    ->options(fn (): array => SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all()),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Không có Sản phẩm nào phát sinh lãi/lỗ trong khoảng đã chọn.');
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (ReportFormat $format): Action => Action::make('export'.ucfirst($format->value))
            ->label("Xuất {$format->label()}")
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Action $action, ProfitReport $report) use ($format): StreamedResponse {
                $export = InventoryAction::attempt($action, fn () => $report->export(InventoryAction::actor(), $this->reportFilter(), $format));

                return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
            }), ReportFormat::cases());
    }

    /**
     * Cột tiền: dòng tổng in đậm, ô rỗng giữ nguyên rỗng (dòng 'Chưa có Giá bán' không có Doanh thu
     * hay Lãi gộp để mà hiện).
     */
    private function money(string $column, string $label): TextColumn
    {
        return TextColumn::make($column)
            ->label($label)
            ->alignEnd()
            ->formatStateUsing(fn (int|string $state): string => $state === '' ? '' : SupplierClaimResource::money((int) $state))
            ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null);
    }

    /**
     * Các dòng báo cáo dưới dạng bản ghi của bảng, khoá là khoá dòng của báo cáo. Mỗi ô lấy qua cell()
     * theo đúng khoá cột của báo cáo, nên bảng và file xuất luôn hiện cùng một con số.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function records(): Collection
    {
        $report = app(ProfitReport::class);
        $filter = $this->reportFilter();
        $columns = array_keys($report->columns($filter));

        /** @var Collection<string, array<string, mixed>> $records */
        $records = collect($report->rows(InventoryAction::actor(), $filter))
            ->mapWithKeys(fn (ProfitReportRow $row): array => [$row->key() => [
                ...array_combine($columns, array_map($row->cell(...), $columns)),
                'is_total' => $row->isTotal(),
                'is_unpriced' => $row->isUnpriced(),
            ]]);

        return $records;
    }

    /**
     * Bộ lọc báo cáo theo trạng thái bộ lọc của bảng.
     */
    private function reportFilter(): ProfitReportFilter
    {
        return ProfitReportFilter::fromTableFilters($this->tableFilters ?? []);
    }
}
