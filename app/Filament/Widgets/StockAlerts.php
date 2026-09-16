<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\StockReportPage;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Reports\StockReport;
use App\Inventory\Reports\StockReportFilter;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cảnh báo tồn kho trên dashboard: Sản phẩm sắp hết hoặc có hàng hết hạn trong 7 ngày, cùng số liệu
 * với báo cáo Tồn kho. Bán hàng không thấy Giá vốn sắp mất.
 */
class StockAlerts extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return app(StockReport::class)->canView(InventoryAction::actor());
    }

    public function mount(): void
    {
        abort_unless(self::canView(), 403);
    }

    public function table(Table $table): Table
    {
        $report = app(StockReport::class);
        $days = StockReportFilter::DEFAULT_EXPIRING_DAYS;

        return $table
            ->heading('Cảnh báo tồn kho')
            ->description("Sản phẩm sắp hết hoặc có hàng hết hạn trong {$days} ngày.")
            ->query(fn (): Builder => $report
                ->query(InventoryAction::actor(), new StockReportFilter(expiringWithinDays: $days, alertsOnly: true))
                ->orderBy('products.name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Sản phẩm'),
                TextColumn::make('sellable_slots')
                    ->label('Tồn bán được')
                    ->badge()
                    ->color(fn (Product $record): string => $record->getAttribute('low_stock') ? 'warning' : 'gray')
                    ->description(fn (Product $record): ?string => $record->getAttribute('low_stock') ? 'Sắp hết' : null),
                TextColumn::make('low_stock_threshold')
                    ->label('Ngưỡng sắp hết')
                    ->placeholder('Không cảnh báo'),
                TextColumn::make('expiring_slots')
                    ->label("Hết hạn trong {$days} ngày")
                    ->suffix(' Slot'),
                TextColumn::make('expiring_cost')
                    ->label('Giá vốn sắp mất')
                    ->formatStateUsing(fn (int|string $state): string => SupplierClaimResource::money((int) $state))
                    ->visible($report->seesCost(InventoryAction::actor())),
            ])
            ->recordUrl(fn (Product $record): string => StockReportPage::getUrl(['filters' => ['products' => ['values' => [$record->id]]]]))
            ->headerActions([
                Action::make('lowStock')
                    ->label('Xem Sản phẩm sắp hết')
                    ->color('gray')
                    ->url(fn (): string => StockReportPage::getUrl(['filters' => ['low_stock' => ['isActive' => true]]])),
                Action::make('expiring')
                    ->label("Xem hàng hết hạn trong {$days} ngày")
                    ->color('gray')
                    ->url(fn (): string => StockReportPage::getUrl(['filters' => ['expiring' => ['days' => $days, 'only' => true]]])),
            ])
            ->emptyStateHeading('Không có cảnh báo tồn kho.');
    }
}
