<?php

namespace App\Filament\Resources\Dispatches\Widgets;

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\CorrectionDraft;
use App\Inventory\Dispatch\CorrectionPreview;
use App\Inventory\Dispatch\CorrectiveDelivery;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Warranty\DefectReportDraft;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectReportStatus;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Bảng Lần giao trên trang xem Phiếu xuất, nội dung luôn dạng che. Xem mã gọi ContentReveal
 * (Bán hàng trong Hạn bảo hành, quá hạn chỉ Quản trị), mỗi lần ghi Nhật ký xem mã. Giao thay qua
 * modal gọi CorrectiveDelivery, rồi hiện mã lần giao mới như Xem mã. Báo lỗi một hoặc nhiều lần
 * giao (chọn nhiều dòng) gọi DefectReporting. Nội dung chỉ nằm trong tham
 * số của modal vừa mở: không lưu ở server, nhưng đi trong snapshot Livewire (không mã hoá) tới
 * trình duyệt cho tới khi modal đóng.
 */
class DispatchDeliveries extends TableWidget
{
    #[Locked]
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public function mount(): void
    {
        abort_unless(InventoryAction::actor()->can('view', $this->dispatchRecord()), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Lần giao')
            ->query(fn (): Builder => Delivery::query()
                ->whereIn('dispatch_line_id', DispatchLine::query()->where('dispatch_id', $this->dispatchRecord()->id)->select('id'))
                ->with(['dispatchLine.dispatch', 'dispatchLine.product', 'slot', 'corrects', 'latestDefectReport', 'stockUnit.product.contentFields']))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('dispatchLine.product.name')
                    ->label('Sản phẩm'),
                TextColumn::make('unit')
                    ->label('Đơn vị hàng')
                    ->state(fn (Delivery $record): string => $record->unitLabel()),
                TextColumn::make('content')
                    ->label('Nội dung (đã che)')
                    ->state(fn (Delivery $record): string => collect($record->stockUnit->maskedContent())
                        ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                        ->implode(' · '))
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('delivered_at')
                    ->label('Giao lúc')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('warranty_ends_on')
                    ->label('Hạn bảo hành')
                    ->state(fn (Delivery $record): string => $record->warrantyEndsOn()->format('d/m/Y')),
                TextColumn::make('slot.status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (SlotStatus $state): string => $state->label())
                    ->color(fn (SlotStatus $state): string => match ($state) {
                        SlotStatus::Delivered => 'success',
                        SlotStatus::Voided => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('corrects')
                    ->label('Giao thay cho')
                    ->state(fn (Delivery $record): ?string => $record->corrects?->unitLabel())
                    ->placeholder('—'),
                TextColumn::make('latestDefectReport.status')
                    ->label('Báo lỗi')
                    ->badge()
                    ->formatStateUsing(fn (DefectReportStatus $state): string => $state->label())
                    ->color(fn (DefectReportStatus $state): string => $state->color())
                    ->url(fn (Delivery $record): ?string => $record->latestDefectReport === null ? null : DefectReportResource::getUrl('view', ['record' => $record->latestDefectReport]))
                    ->placeholder('—'),
            ])
            ->toolbarActions([
                BulkAction::make('report')
                    ->label('Báo lỗi')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->modalHeading('Báo lỗi các Slot đã chọn')
                    ->modalDescription('Mỗi Slot một Báo lỗi Chờ xác minh; trong lúc chờ, Slot còn trong kho của cùng Đơn vị hàng tạm ngừng bán.')
                    ->modalSubmitActionLabel('Tạo Báo lỗi')
                    ->schema(fn (Collection $records): array => self::reportSchema(self::selectedDeliveries($records)))
                    ->visible(fn (RoleGate $roles): bool => $roles->allows(InventoryAction::actor(), Role::BanHang))
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (BulkAction $action, Collection $records, array $data, DefectReporting $reports) => self::report($action, $reports, self::selectedDeliveries($records), $data)),
            ])
            ->recordActions([
                Action::make('report')
                    ->label('Báo lỗi')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->modalHeading(fn (Delivery $record): string => "Báo lỗi Slot #{$record->slot_id}")
                    ->modalDescription('Trong lúc Chờ xác minh, Slot còn trong kho của cùng Đơn vị hàng tạm ngừng bán.')
                    ->modalSubmitActionLabel('Tạo Báo lỗi')
                    ->schema(fn (Delivery $record): array => self::reportSchema([$record]))
                    ->visible(fn (Delivery $record, DefectReporting $reports): bool => $reports->canReport(InventoryAction::actor(), $record))
                    ->action(fn (Action $action, Delivery $record, array $data, DefectReporting $reports) => self::report($action, $reports, [$record], $data)),
                Action::make('reveal')
                    ->label('Xem mã')
                    ->icon(Heroicon::OutlinedEye)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Delivery $record): string => "Xem mã Slot #{$record->slot_id}")
                    ->modalDescription('Nội dung đầy đủ hiện ra để gửi lại cho khách. Lần xem được ghi vào Nhật ký xem mã.')
                    ->modalSubmitActionLabel('Xem mã')
                    ->visible(fn (Delivery $record): bool => app(ContentReveal::class)->canRevealDelivery(InventoryAction::actor(), $record))
                    ->action(function (Action $action, Delivery $record, ContentReveal $reveal): void {
                        $content = InventoryAction::attempt($action, fn () => $reveal->revealDelivery(InventoryAction::actor(), $record));

                        $this->replaceMountedAction('revealedDelivery', [
                            'slot' => $record->slot_id,
                            'message' => (string) $content->message,
                        ]);
                    }),
                Action::make('correct')
                    ->label('Giao thay')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->modalHeading(fn (Delivery $record): string => "Giao thay Slot #{$record->slot_id}")
                    ->modalSubmitActionLabel('Giao thay')
                    ->fillForm(fn (Delivery $record): array => ['product_id' => $record->stockUnit->product_id, 'void_unit' => 0])
                    ->schema(fn (Delivery $record): array => self::correctionSchema($record, app(CorrectiveDelivery::class)->preview(InventoryAction::actor(), $record)))
                    ->visible(fn (Delivery $record): bool => app(CorrectiveDelivery::class)->canCorrect(InventoryAction::actor(), $record))
                    ->action(function (Action $action, Delivery $record, array $data, CorrectiveDelivery $corrective, ContentReveal $reveal): void {
                        $contentSent = (bool) ($data['content_sent'] ?? false);
                        $voidUnit = $contentSent && (bool) ($data['void_unit'] ?? false);
                        $new = InventoryAction::attempt($action, fn () => $corrective->correct(InventoryAction::actor(), $record, new CorrectionDraft(
                            product: Product::query()->find($data['product_id'] ?? null),
                            contentSent: $contentSent,
                            voidUnit: $voidUnit,
                            reason: $data['reason'] ?? null,
                        )));

                        // Báo trước khi xem mã: Giao thay đã commit dù bước xem mã có lỗi. Huỷ cả Đơn vị
                        // hàng thì giữ danh sách Lần giao bị ảnh hưởng trên màn hình để liên hệ khách.
                        $notification = Notification::make()->success()->title('Đã Giao thay.');

                        if ($voidUnit) {
                            $notification->body(self::affectedList(AffectedDelivery::forUnit($record->stock_unit_id, $record->id)))->persistent();
                        }

                        $notification->send();
                        $content = InventoryAction::attempt($action, fn () => $reveal->revealDelivery(InventoryAction::actor(), $new));

                        $this->replaceMountedAction('revealedDelivery', [
                            'slot' => $new->slot_id,
                            'message' => (string) $content->message,
                        ]);
                    }),
            ]);
    }

    public function revealedDeliveryAction(): Action
    {
        return Action::make('revealedDelivery')
            ->modalHeading(fn (array $arguments): string => "Nội dung Slot #{$arguments['slot']}")
            ->schema(fn (array $arguments): array => [
                TextEntry::make('message')
                    ->hiddenLabel()
                    ->state((string) ($arguments['message'] ?? ''))
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->copyMessage('Đã copy.'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }

    /**
     * Form Giao thay: tóm tắt lần giao sẽ bị huỷ, Sản phẩm giao thay, nội dung đã gửi chưa (Giữ
     * nguyên hoặc Huỷ hàng cả Đơn vị hàng kèm Lần giao bị ảnh hưởng) và lý do khi quá hạn.
     *
     * @return list<mixed>
     */
    private static function correctionSchema(Delivery $delivery, CorrectionPreview $preview): array
    {
        $sent = fn (Get $get): bool => (bool) $get('content_sent');

        return [
            Text::make(new HtmlString(sprintf(
                'Lần giao sẽ bị Huỷ hàng (giao nhầm): <strong>%s</strong> · %s, giao lúc %s. %s.%s',
                e($preview->productName),
                e($preview->unitLabel),
                $preview->deliveredAt->format('d/m/Y H:i'),
                e($preview->remainingLabel()),
                $preview->late ? ' Quá hạn: chỉ Quản trị Giao thay, bắt buộc nhập lý do.' : '',
            ))),
            Select::make('product_id')
                ->label('Sản phẩm giao thay')
                ->options(fn (): array => Product::query()
                    ->where(fn (Builder $query) => $query->onSale()->orWhere('id', $delivery->stockUnit->product_id))
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->code} · {$product->name}"])
                    ->all())
                ->searchable()
                ->required()
                ->helperText('Cùng Sản phẩm thì dùng Dòng xuất gốc; Sản phẩm khác thì thêm Dòng xuất loại Giao thay, chưa có Giá bán.'),
            Radio::make('content_sent')
                ->label('Nội dung đã gửi cho khách chưa?')
                ->boolean('Đã gửi', 'Chưa gửi')
                ->required()
                ->live(),
            Radio::make('void_unit')
                ->label('Đơn vị hàng đã lộ nội dung')
                ->boolean('Huỷ hàng cả Đơn vị hàng', 'Giữ nguyên')
                ->live()
                ->visible($sent),
            Text::make(self::affectedList($preview->affected))
                ->visible(fn (Get $get): bool => $sent($get) && (bool) $get('void_unit')),
            Textarea::make('reason')
                ->label('Lý do')
                ->rows(2)
                ->required($preview->late)
                ->visible($preview->late),
        ];
    }

    /**
     * Form Báo lỗi: các lần Bác bỏ trước của Slot, mô tả bắt buộc, ảnh tuỳ chọn và lý do khi Quản trị
     * Báo lỗi ngoài Hạn bảo hành.
     *
     * @param  list<Delivery>  $deliveries
     * @return list<mixed>
     */
    private static function reportSchema(array $deliveries): array
    {
        $actor = InventoryAction::actor();
        $rejected = app(DefectReporting::class)->rejectedReports($actor, $deliveries);

        return [
            Text::make(new HtmlString('Các lần Bác bỏ trước:<br>'.$rejected->map(fn (DefectReport $report): string => e(sprintf(
                'Slot #%d · %s · %s → %s',
                $report->slot_id,
                $report->created_at->format('d/m/Y H:i'),
                $report->description,
                $report->verification_note,
            )))->implode('<br>')))
                ->visible($rejected->isNotEmpty()),
            Textarea::make('description')
                ->label('Mô tả lỗi')
                ->rows(3)
                ->required(),
            // Không lưu ở đây: DefectReporting chỉ lưu ảnh khi Báo lỗi tạo thành công.
            FileUpload::make('screenshot')
                ->label('Ảnh (tuỳ chọn)')
                ->image()
                ->storeFiles(false),
            Textarea::make('override_reason')
                ->label('Lý do vượt Hạn bảo hành')
                ->helperText('Có lần giao ngoài Hạn bảo hành hoặc Sản phẩm không có bảo hành; chỉ Quản trị Báo lỗi được, bắt buộc lý do.')
                ->rows(2)
                // Không truyền Vai trò nào: chỉ Quản trị vượt Hạn bảo hành.
                ->visible(app(RoleGate::class)->allows($actor) && collect($deliveries)->contains(fn (Delivery $delivery): bool => DefectReporting::isOutOfWarranty($delivery))),
        ];
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return list<Delivery>
     */
    private static function selectedDeliveries(Collection $records): array
    {
        return $records->map(function (Model $record): Delivery {
            assert($record instanceof Delivery);

            return $record;
        })->values()->all();
    }

    /**
     * @param  list<Delivery>  $deliveries
     * @param  array<string, mixed>  $data
     */
    private static function report(Action $action, DefectReporting $reports, array $deliveries, array $data): void
    {
        $state = $data['screenshot'] ?? null;
        $screenshot = is_array($state) ? reset($state) : $state;
        $screenshot = $screenshot instanceof UploadedFile ? $screenshot : null;

        try {
            $created = InventoryAction::attempt($action, fn () => $reports->report(InventoryAction::actor(), $deliveries, new DefectReportDraft(
                description: (string) ($data['description'] ?? ''),
                screenshot: $screenshot,
                overrideReason: $data['override_reason'] ?? null,
            )));
        } finally {
            // File tạm của Livewire: DefectReporting đã chép sang disk riêng nếu Báo lỗi tạo được.
            if ($screenshot instanceof TemporaryUploadedFile) {
                $screenshot->delete();
            }
        }

        Notification::make()->success()->title(sprintf('Đã tạo %d Báo lỗi Chờ xác minh.', count($created)))->send();
    }

    /**
     * @param  list<AffectedDelivery>  $affected
     */
    private static function affectedList(array $affected): HtmlString
    {
        if ($affected === []) {
            return new HtmlString('Đơn vị hàng không còn lần giao nào khác.');
        }

        return new HtmlString('Lần giao bị ảnh hưởng: hãy liên hệ khách; hệ thống không tự Báo lỗi hay Đổi hàng.<br>'.implode('<br>', array_map(
            fn (AffectedDelivery $delivery): string => e($delivery->label()),
            $affected,
        )));
    }

    private function dispatchRecord(): Dispatch
    {
        $record = $this->record;
        assert($record instanceof Dispatch);

        return $record;
    }
}
