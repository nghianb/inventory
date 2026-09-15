<?php

namespace App\Filament\Resources\SupplierClaims\Widgets;

use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Claims\SupplierClaims;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Đơn vị hàng Lỗi chưa khiếu nại, trên danh sách Khiếu nại nhà cung cấp. Chọn nhiều Đơn vị hàng của
 * cùng một Nhà cung cấp để tạo Khiếu nại Nháp (SupplierClaims). Nội dung dạng che.
 */
class UnclaimedDefectiveUnits extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return InventoryAction::actor()->can('viewAny', SupplierClaim::class);
    }

    public function mount(): void
    {
        abort_unless(self::canView(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Đơn vị hàng Lỗi chưa khiếu nại')
            ->query(fn (): Builder => app(SupplierClaims::class)->unclaimedDefectiveUnits()->with(['product.contentFields', 'batchLine.batch.supplier']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')
                    ->label('Đơn vị hàng')
                    ->prefix('#')
                    ->url(fn (StockUnit $record): string => StockUnitResource::getUrl('view', ['record' => $record])),
                TextColumn::make('batchLine.batch.supplier.name')
                    ->label('Nhà cung cấp'),
                TextColumn::make('product.name')
                    ->label('Sản phẩm'),
                TextColumn::make('content')
                    ->label('Nội dung (đã che)')
                    ->state(fn (StockUnit $record): string => collect($record->maskedContent())
                        ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                        ->implode(' · ')),
                TextColumn::make('unit_cost')
                    ->label('Giá vốn')
                    ->formatStateUsing(fn (int $state): string => SupplierClaimResource::money($state)),
                TextColumn::make('defective_at')
                    ->label('Chuyển Lỗi lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier')
                    ->label('Nhà cung cấp')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('batchLine.batch', fn (Builder $batches) => $batches->where('supplier_id', $data['value']))),
            ])
            ->toolbarActions([
                BulkAction::make('createClaim')
                    ->label('Tạo Khiếu nại')
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->requiresConfirmation()
                    ->modalHeading('Tạo Khiếu nại Nháp')
                    ->modalDescription('Các Đơn vị hàng đã chọn phải cùng một Nhà cung cấp.')
                    ->action(function (BulkAction $action, Collection $records, SupplierClaims $claims): void {
                        $units = StockUnit::query()->with('batchLine.batch.supplier')->whereKey($records->modelKeys())->orderBy('id')->get();
                        $first = $units->first();
                        assert($first instanceof StockUnit);

                        // Nhà cung cấp của Đơn vị hàng đầu tiên; SupplierClaims từ chối Đơn vị hàng của Nhà cung cấp khác.
                        $claim = InventoryAction::attempt($action, fn () => $claims->create(InventoryAction::actor(), $first->batchLine->batch->supplier, $units->all()));

                        Notification::make()->success()->title("Đã tạo Khiếu nại Nháp #{$claim->id}.")->send();
                        $this->redirect(SupplierClaimResource::getUrl('view', ['record' => $claim]));
                    }),
            ]);
    }
}
