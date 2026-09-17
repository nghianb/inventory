<?php

namespace App\Filament\Resources\SupplierClaims;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\SupplierClaims\Pages\CreateSupplierClaim;
use App\Filament\Resources\SupplierClaims\Pages\ListSupplierClaims;
use App\Filament\Resources\SupplierClaims\Pages\ViewSupplierClaim;
use App\Filament\Resources\SupplierClaims\RelationManagers\ClaimUnitsRelationManager;
use App\Filament\Resources\SupplierClaims\Widgets\UnclaimedDefectiveUnits;
use App\Filament\Support\NavGroup;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Claims\SupplierClaimStatus;
use App\Models\Batch;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Khiếu nại nhà cung cấp trong panel. Adapter mỏng: tạo, sửa Nháp, gửi, giải quyết và huỷ gọi
 * SupplierClaims; Xem mã gọi ContentReveal. Danh sách kèm bảng Đơn vị hàng Lỗi chưa khiếu nại. Nội
 * dung luôn dạng che. Bán hàng không thấy.
 */
class SupplierClaimResource extends Resource
{
    protected static ?string $model = SupplierClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::NhapHang;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'khiếu nại nhà cung cấp';

    protected static ?string $pluralModelLabel = 'Khiếu nại nhà cung cấp';

    protected static ?string $navigationLabel = 'Khiếu nại nhà cung cấp';

    protected static ?string $slug = 'khieu-nai-nha-cung-cap';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label('Nhà cung cấp')
                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live(),
            Select::make('stock_unit_ids')
                ->label('Đơn vị hàng Lỗi chưa khiếu nại')
                ->multiple()
                ->options(fn (Get $get): array => filled($get('supplier_id')) ? self::unclaimedOptions((int) $get('supplier_id')) : [])
                ->searchable()
                ->required(),
            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2),
        ])->columns(1);
    }

    /**
     * Đơn vị hàng Lỗi chưa khiếu nại của một Nhà cung cấp, nhãn dạng che.
     *
     * @return array<int, string>
     */
    public static function unclaimedOptions(int $supplierId): array
    {
        return app(SupplierClaims::class)->unclaimedDefectiveUnits()
            ->whereHas('batchLine.batch', fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->with('product.contentFields')
            ->orderBy('stock_units.id')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (StockUnit $unit): array => [$unit->id => self::unitLabel($unit)])
            ->all();
    }

    public static function unitLabel(StockUnit $unit): string
    {
        return sprintf('#%d · %s · %s', $unit->id, $unit->product->name, collect($unit->maskedContent())
            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
            ->implode(' · '));
    }

    public static function money(?int $amount): string
    {
        return number_format((int) $amount, 0, ',', '.').' ₫';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Khiếu nại')
                ->columns(3)
                ->schema([
                    TextEntry::make('supplier.name')->label('Nhà cung cấp'),
                    TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn (SupplierClaimStatus $state): string => $state->label())
                        ->color(fn (SupplierClaimStatus $state): string => $state->color()),
                    TextEntry::make('creator.name')->label('Người tạo'),
                    TextEntry::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i'),
                    TextEntry::make('sent_at')
                        ->label('Gửi lúc')
                        ->dateTime('d/m/Y H:i')
                        ->helperText(fn (SupplierClaim $record): ?string => $record->sender?->name)
                        ->visible(fn (SupplierClaim $record): bool => $record->sent_at !== null),
                    TextEntry::make('resolved_at')
                        ->label('Giải quyết lúc')
                        ->dateTime('d/m/Y H:i')
                        ->helperText(fn (SupplierClaim $record): ?string => $record->resolver?->name)
                        ->visible(fn (SupplierClaim $record): bool => $record->resolved_at !== null),
                    TextEntry::make('refund_total')
                        ->label('Tổng bồi hoàn tiền')
                        ->state(fn (SupplierClaim $record): string => self::money((int) $record->claimUnits()->where('active', true)->sum('refund_amount')))
                        ->visible(fn (SupplierClaim $record): bool => $record->status === SupplierClaimStatus::Resolved),
                    TextEntry::make('cancelled_at')
                        ->label('Huỷ lúc')
                        ->dateTime('d/m/Y H:i')
                        ->helperText(fn (SupplierClaim $record): ?string => $record->canceller?->name)
                        ->visible(fn (SupplierClaim $record): bool => $record->cancelled_at !== null),
                    TextEntry::make('cancel_reason')
                        ->label('Lý do huỷ')
                        ->visible(fn (SupplierClaim $record): bool => $record->cancel_reason !== null)
                        ->columnSpanFull(),
                    TextEntry::make('note')->label('Ghi chú')->placeholder('Không có')->columnSpanFull(),
                ]),
            RepeatableEntry::make('replacement_batches')
                ->label('Lô nhập hàng thay thế')
                ->visible(fn (SupplierClaim $record): bool => SupplierClaim::query()->acceptsReplacementGoods()->whereKey($record->id)->exists())
                ->state(fn (SupplierClaim $record): array => $record->batches()->get()->map(fn (Batch $batch): array => [
                    'id' => $batch->id,
                    'received_on' => $batch->received_on->format('d/m/Y'),
                    'status' => $batch->status->label(),
                ])->all())
                ->placeholder('Chưa nhập hàng thay thế.')
                ->table([
                    TableColumn::make('Lô nhập'),
                    TableColumn::make('Ngày nhập'),
                    TableColumn::make('Trạng thái'),
                ])
                ->schema([
                    TextEntry::make('id')
                        ->prefix('#')
                        ->url(fn (int $state): string => BatchResource::getUrl('view', ['record' => $state])),
                    TextEntry::make('received_on'),
                    TextEntry::make('status')->badge(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['supplier', 'creator'])
                ->withCount(['claimUnits as active_units_count' => fn (Builder $units) => $units->where('active', true)]))
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('supplier.name')
                    ->label('Nhà cung cấp')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (SupplierClaimStatus $state): string => $state->label())
                    ->color(fn (SupplierClaimStatus $state): string => $state->color()),
                TextColumn::make('active_units_count')
                    ->label('Số Đơn vị hàng'),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Người tạo'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(collect(SupplierClaimStatus::cases())->mapWithKeys(fn (SupplierClaimStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('supplier')
                    ->label('Nhà cung cấp')
                    ->relationship('supplier', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ClaimUnitsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            UnclaimedDefectiveUnits::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierClaims::route('/'),
            'create' => CreateSupplierClaim::route('/tao'),
            'view' => ViewSupplierClaim::route('/{record}'),
        ];
    }
}
