<?php

namespace App\Filament\Resources\DefectReports;

use App\Filament\Resources\DefectReports\Pages\ListDefectReports;
use App\Filament\Resources\DefectReports\Pages\ViewDefectReport;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Resources\StockUnits\StockUnitResource;
use App\Filament\Support\NavGroup;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Warranty\DefectReportStatus;
use App\Inventory\Warranty\DefectResolution;
use App\Models\DefectReport;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Báo lỗi trong panel: danh sách (tab tồn đọng Chờ xác minh quá hạn) và trang xác minh. Adapter
 * mỏng: tạo từ bảng Lần giao của Phiếu xuất, xác minh và xem mã gọi DefectReporting, ContentReveal.
 * Nội dung luôn dạng che. Nhập kho không thấy.
 */
class DefectReportResource extends Resource
{
    protected static ?string $model = DefectReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::XuatHang;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'báo lỗi';

    protected static ?string $pluralModelLabel = 'Báo lỗi';

    protected static ?string $navigationLabel = 'Báo lỗi';

    protected static ?string $slug = 'bao-loi';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Báo lỗi')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label('Trạng thái')
                        ->badge()
                        ->formatStateUsing(fn (DefectReportStatus $state): string => $state->label())
                        ->color(fn (DefectReportStatus $state): string => $state->color()),
                    TextEntry::make('creator.name')->label('Người tạo'),
                    TextEntry::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i'),
                    TextEntry::make('description')->label('Mô tả')->columnSpanFull(),
                    TextEntry::make('warranty_override_reason')
                        ->label('Lý do vượt Hạn bảo hành')
                        ->visible(fn (DefectReport $record): bool => $record->warranty_override_reason !== null)
                        ->columnSpanFull(),
                    TextEntry::make('source_defect_report_id')
                        ->label('Tự Xác nhận theo')
                        ->formatStateUsing(fn (int $state): string => "Báo lỗi #{$state}")
                        ->url(fn (DefectReport $record): ?string => $record->source_defect_report_id === null ? null : self::getUrl('view', ['record' => $record->source_defect_report_id]))
                        ->visible(fn (DefectReport $record): bool => $record->source_defect_report_id !== null),
                    ImageEntry::make('screenshot_path')
                        ->label('Ảnh')
                        ->disk('local')
                        ->visibility('private')
                        ->visible(fn (DefectReport $record): bool => $record->screenshot_path !== null)
                        ->columnSpanFull(),
                ]),
            Section::make('Lần giao')
                ->columns(3)
                ->schema([
                    TextEntry::make('delivery.dispatchLine.dispatch.external_ref')
                        ->label('Phiếu xuất')
                        ->url(fn (DefectReport $record): string => DispatchResource::getUrl('view', ['record' => $record->delivery->dispatchLine->dispatch_id])),
                    TextEntry::make('delivery.dispatchLine.dispatch.salesChannel.name')->label('Kênh bán'),
                    TextEntry::make('delivery.dispatchLine.dispatch.customer')->label('Khách')->placeholder('Không có'),
                    TextEntry::make('stockUnit.product.name')->label('Sản phẩm'),
                    TextEntry::make('unit')
                        ->label('Đơn vị hàng')
                        ->state(fn (DefectReport $record): string => $record->delivery->unitLabel())
                        ->helperText('Đơn vị hàng Lỗi: xem Lần giao bị ảnh hưởng ở chi tiết Đơn vị hàng.')
                        ->url(fn (DefectReport $record): string => StockUnitResource::getUrl('view', ['record' => $record->stock_unit_id])),
                    TextEntry::make('stockUnit.status')
                        ->label('Trạng thái Đơn vị hàng')
                        ->badge()
                        ->formatStateUsing(fn (mixed $state): string => $state->label()),
                    TextEntry::make('delivery.delivered_at')->label('Giao lúc')->dateTime('d/m/Y H:i'),
                    TextEntry::make('warranty_ends_on')
                        ->label('Hạn bảo hành')
                        ->state(fn (DefectReport $record): string => $record->delivery->warrantyEndsOn()->format('d/m/Y')),
                    TextEntry::make('content')
                        ->label('Nội dung (đã che)')
                        ->state(fn (DefectReport $record): string => collect($record->stockUnit->maskedContent())
                            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                            ->implode(' · ')),
                ]),
            Section::make('Xác minh')
                ->columns(3)
                ->visible(fn (DefectReport $record): bool => $record->status !== DefectReportStatus::Pending)
                ->schema([
                    TextEntry::make('scope')
                        ->label('Phạm vi lỗi')
                        ->formatStateUsing(fn (mixed $state): string => $state->label())
                        ->placeholder('—'),
                    TextEntry::make('verifier.name')->label('Người xác minh'),
                    TextEntry::make('verified_at')->label('Xác minh lúc')->dateTime('d/m/Y H:i'),
                    TextEntry::make('verification_note')->label('Ghi chú')->columnSpanFull(),
                ]),
            // Không hiện Chi phí đổi hàng hay Nhà cung cấp: Bán hàng không được thấy.
            Section::make('Kết quả xử lý')
                ->columns(3)
                ->visible(fn (DefectReport $record): bool => $record->resolution !== null)
                ->schema([
                    TextEntry::make('resolution')
                        ->label('Kết quả xử lý')
                        ->badge()
                        ->formatStateUsing(fn (DefectResolution $state): string => $state->label())
                        ->color(fn (DefectResolution $state): string => $state->color()),
                    TextEntry::make('resolver.name')->label('Người xử lý')->placeholder('—'),
                    TextEntry::make('resolved_at')->label('Xử lý lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('approvalRequester.name')
                        ->label('Yêu cầu duyệt Đổi hàng')
                        ->visible(fn (DefectReport $record): bool => $record->replacement_approval_requested_by !== null),
                    TextEntry::make('replacementApprover.name')
                        ->label('Quản trị duyệt Đổi hàng')
                        ->visible(fn (DefectReport $record): bool => $record->replacement_approved_by !== null),
                    TextEntry::make('resolution_note')
                        ->label('Lý do Không đổi')
                        ->visible(fn (DefectReport $record): bool => $record->resolution === DefectResolution::NotReplaced)
                        ->columnSpanFull(),
                    TextEntry::make('refunded_note')
                        ->label('Hoàn tiền ngoài kho')
                        ->state(fn (DefectReport $record): string => $record->refunded
                            ? 'Khách đã được hoàn tiền: Giá bán của Dòng xuất cần sửa xuống số tiền shop thực giữ (Sửa phiếu).'
                            : 'Không')
                        ->visible(fn (DefectReport $record): bool => $record->resolution === DefectResolution::NotReplaced)
                        ->columnSpanFull(),
                    TextEntry::make('replacement_delivery')
                        ->label('Lần giao Đổi hàng')
                        ->state(fn (DefectReport $record): ?string => $record->replacement === null ? null : sprintf(
                            '%s · lần đổi thứ %d trong chuỗi · giao %s',
                            $record->replacement->delivery->unitLabel(),
                            $record->replacement->sequence,
                            $record->replacement->delivery->delivered_at->format('d/m/Y H:i'),
                        ))
                        ->url(fn (DefectReport $record): ?string => $record->replacement === null ? null : DispatchResource::getUrl('view', ['record' => $record->delivery->dispatchLine->dispatch_id]))
                        ->visible(fn (DefectReport $record): bool => $record->replacement !== null)
                        ->columnSpanFull(),
                    TextEntry::make('replacement.product_change_reason')
                        ->label('Lý do đổi sang Sản phẩm khác')
                        ->visible(fn (DefectReport $record): bool => $record->replacement?->product_change_reason !== null)
                        ->columnSpanFull(),
                ]),
            RepeatableEntry::make('slot_history')
                ->label('Báo lỗi khác của Slot')
                ->state(fn (DefectReport $record): array => DefectReport::query()
                    ->with('verifier')
                    ->where('slot_id', $record->slot_id)
                    ->whereKeyNot($record->id)
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (DefectReport $other): array => [
                        'created_at' => $other->created_at->format('d/m/Y H:i'),
                        'status' => $other->status->label(),
                        'description' => $other->description,
                        'note' => $other->verification_note,
                    ])->all())
                ->placeholder('Không có.')
                ->table([
                    TableColumn::make('Tạo lúc'),
                    TableColumn::make('Trạng thái'),
                    TableColumn::make('Mô tả'),
                    TableColumn::make('Ghi chú xác minh'),
                ])
                ->schema([
                    TextEntry::make('created_at'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('description'),
                    TextEntry::make('note')->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['delivery.dispatchLine.dispatch', 'stockUnit.product', 'creator']))
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('delivery.dispatchLine.dispatch.external_ref')
                    ->label('Phiếu xuất'),
                TextColumn::make('delivery.dispatchLine.dispatch.customer')
                    ->label('Khách')
                    ->limit(40),
                TextColumn::make('stockUnit.product.name')
                    ->label('Sản phẩm'),
                TextColumn::make('unit')
                    ->label('Đơn vị hàng')
                    ->state(fn (DefectReport $record): string => $record->delivery->unitLabel()),
                TextColumn::make('description')
                    ->label('Mô tả')
                    ->limit(60),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (DefectReportStatus $state): string => $state->label())
                    ->color(fn (DefectReportStatus $state): string => $state->color()),
                TextColumn::make('resolution')
                    ->label('Kết quả xử lý')
                    ->badge()
                    ->formatStateUsing(fn (DefectResolution $state): string => $state->label())
                    ->color(fn (DefectResolution $state): string => $state->color())
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label('Người tạo'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(collect(DefectReportStatus::cases())->mapWithKeys(fn (DefectReportStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Danh sách Lần giao bị ảnh hưởng để liên hệ khách, dạng chữ.
     *
     * @param  list<AffectedDelivery>  $affected
     */
    public static function affectedList(array $affected): HtmlString
    {
        if ($affected === []) {
            return new HtmlString('Đơn vị hàng không còn lần giao nào khác.');
        }

        return new HtmlString('Lần giao bị ảnh hưởng: hãy liên hệ khách; hệ thống không tự Đổi hàng.<br>'.implode('<br>', array_map(
            fn (AffectedDelivery $delivery): string => e($delivery->label()),
            $affected,
        )));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDefectReports::route('/'),
            'view' => ViewDefectReport::route('/{record}'),
        ];
    }
}
