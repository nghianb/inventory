<?php

namespace App\Filament\Resources\DefectReports\Pages;

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectScope;
use App\Inventory\Warranty\ReplacementAvailability;
use App\Inventory\Warranty\ReplacementDelivery;
use App\Inventory\Warranty\ReplacementDraft;
use App\Inventory\Warranty\ReplacementPreview;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Trang xác minh Báo lỗi: Xem mã (ghi Nhật ký xem mã ngữ cảnh Báo lỗi), Xác nhận với Phạm vi lỗi
 * hoặc Bác bỏ, kèm ghi chú. Đơn vị hàng chuyển Lỗi thì liệt kê Lần giao bị ảnh hưởng và cho tạo Báo
 * lỗi hàng loạt tự Xác nhận. Báo lỗi Chờ đổi thì Đổi hàng (gọi ReplacementDelivery, rồi hiện mã
 * lần giao mới qua màn kết quả Đổi hàng) hoặc Không đổi; từ lần đổi thứ 3 Bán hàng yêu cầu và Quản
 * trị duyệt ở đây. Nội dung chỉ nằm trong tham số của modal vừa mở.
 */
class ViewDefectReport extends ViewRecord
{
    protected static string $resource = DefectReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reveal')
                ->label('Xem mã')
                ->icon(Heroicon::OutlinedEye)
                ->requiresConfirmation()
                ->modalHeading('Xem mã để xác minh')
                ->modalDescription('Nội dung đầy đủ hiện ra để kiểm tra lỗi. Lần xem được ghi vào Nhật ký xem mã.')
                ->modalSubmitActionLabel('Xem mã')
                ->visible(fn (ContentReveal $reveal): bool => $reveal->canRevealDefectReport(InventoryAction::actor(), $this->reportRecord()))
                ->action(function (Action $action, ContentReveal $reveal): void {
                    $content = InventoryAction::attempt($action, fn () => $reveal->revealDefectReport(InventoryAction::actor(), $this->reportRecord()));

                    $this->replaceMountedAction('revealedContent', [
                        'slot' => $content->slotId,
                        'message' => (string) $content->message,
                    ]);
                }),
            Action::make('confirm')
                ->label('Xác nhận')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('danger')
                ->modalHeading('Xác nhận Báo lỗi')
                ->modalSubmitActionLabel('Xác nhận')
                ->fillForm(['scope' => DefectScope::Unit->value])
                ->schema([
                    Radio::make('scope')
                        ->label('Phạm vi lỗi')
                        ->options(DefectScope::options())
                        ->descriptions([
                            DefectScope::Unit->value => 'Đơn vị hàng chuyển Lỗi, Slot còn trong kho không bán được, tính vào Tỉ lệ lỗi Nhà cung cấp.',
                            DefectScope::Slot->value => 'Đơn vị hàng vẫn Hoạt động, ví dụ một profile bị khách khác phá.',
                        ])
                        ->required(),
                    Textarea::make('note')
                        ->label('Ghi chú xác minh')
                        ->rows(2)
                        ->required(),
                ])
                ->visible(fn (DefectReporting $reports): bool => $reports->canVerify(InventoryAction::actor(), $this->reportRecord()))
                ->action(function (Action $action, DefectReporting $reports, array $data): void {
                    $scope = DefectScope::from($data['scope']);

                    InventoryAction::attempt($action, fn () => $reports->confirm(InventoryAction::actor(), $this->reportRecord(), $scope, (string) $data['note']));
                    $this->reportRecord()->refresh();

                    $notification = Notification::make()->success()->title('Đã Xác nhận Báo lỗi.');

                    // Đơn vị hàng vừa chuyển Lỗi: giữ danh sách Lần giao bị ảnh hưởng trên màn hình để liên hệ khách.
                    if ($scope === DefectScope::Unit) {
                        $notification->body(DefectReportResource::affectedList($reports->affectedDeliveries(InventoryAction::actor(), $this->reportRecord())))->persistent();
                    }

                    $notification->send();
                }),
            Action::make('reject')
                ->label('Bác bỏ')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->modalHeading('Bác bỏ Báo lỗi')
                ->modalDescription('Slot còn trong kho của Đơn vị hàng bán lại được.')
                ->modalSubmitActionLabel('Bác bỏ')
                ->schema([
                    Textarea::make('note')
                        ->label('Ghi chú xác minh')
                        ->rows(2)
                        ->required(),
                ])
                ->visible(fn (DefectReporting $reports): bool => $reports->canVerify(InventoryAction::actor(), $this->reportRecord()))
                ->action(function (Action $action, DefectReporting $reports, array $data): void {
                    InventoryAction::attempt($action, fn () => $reports->reject(InventoryAction::actor(), $this->reportRecord(), (string) $data['note']));
                    $this->reportRecord()->refresh();

                    Notification::make()->success()->title('Đã Bác bỏ Báo lỗi.')->send();
                }),
            Action::make('replace')
                ->label('Đổi hàng')
                ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->color('success')
                ->modalHeading('Đổi hàng')
                ->modalSubmitActionLabel('Đổi hàng')
                ->fillForm(fn (): array => ['product_id' => $this->reportRecord()->stockUnit->product_id, 'accept_shorter_expiry' => false])
                ->schema(fn (): array => $this->replacementSchema())
                // Bán hàng vẫn mở được modal ở lần đổi cần duyệt để thấy cảnh báo; nút gửi bị khoá tới khi Quản trị duyệt.
                ->visible(fn (DefectReporting $reports): bool => $reports->canDeclineReplacement(InventoryAction::actor(), $this->reportRecord()))
                ->modalSubmitAction(fn (Action $submit): Action => $submit->disabled(! app(ReplacementDelivery::class)->canReplace(InventoryAction::actor(), $this->reportRecord())))
                ->action(function (Action $action, array $data, ReplacementDelivery $replacements, ContentReveal $reveal): void {
                    $replacement = InventoryAction::attempt($action, fn () => $replacements->replace(InventoryAction::actor(), $this->reportRecord(), new ReplacementDraft(
                        product: Product::query()->find($data['product_id'] ?? null),
                        productChangeReason: $data['product_change_reason'] ?? null,
                        acceptShorterExpiry: (bool) ($data['accept_shorter_expiry'] ?? false),
                    )));
                    $this->reportRecord()->refresh();

                    // Báo trước khi xem mã: Đổi hàng đã commit dù bước xem mã có lỗi.
                    Notification::make()->success()->title('Đã Đổi hàng.')->send();
                    $content = InventoryAction::attempt($action, fn () => $reveal->revealReplacement(InventoryAction::actor(), $replacement));

                    $this->replaceMountedAction('revealedContent', [
                        'slot' => $content->slotId,
                        'message' => (string) $content->message,
                    ]);
                }),
            Action::make('requestReplacementApproval')
                ->label('Yêu cầu Quản trị duyệt')
                ->icon(Heroicon::OutlinedHandRaised)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Yêu cầu Quản trị duyệt Đổi hàng')
                ->modalDescription('Từ lần đổi thứ '.ReplacementDelivery::APPROVAL_SEQUENCE.' trong chuỗi, Đổi hàng cần Quản trị duyệt. Báo lỗi vào danh sách Chờ Quản trị duyệt.')
                ->modalSubmitActionLabel('Gửi yêu cầu')
                ->visible(fn (ReplacementDelivery $replacements): bool => $replacements->canRequestApproval(InventoryAction::actor(), $this->reportRecord()))
                ->action(function (Action $action, ReplacementDelivery $replacements): void {
                    InventoryAction::attempt($action, fn () => $replacements->requestApproval(InventoryAction::actor(), $this->reportRecord()));
                    $this->reportRecord()->refresh();

                    Notification::make()->success()->title('Đã yêu cầu Quản trị duyệt Đổi hàng.')->send();
                }),
            Action::make('approveReplacement')
                ->label('Duyệt Đổi hàng')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Duyệt Đổi hàng')
                ->modalDescription('Cho Bán hàng Đổi hàng lần này dù đã từ lần đổi thứ '.ReplacementDelivery::APPROVAL_SEQUENCE.' trong chuỗi.')
                ->modalSubmitActionLabel('Duyệt')
                ->visible(fn (ReplacementDelivery $replacements): bool => $replacements->canApprove(InventoryAction::actor(), $this->reportRecord()))
                ->action(function (Action $action, ReplacementDelivery $replacements): void {
                    InventoryAction::attempt($action, fn () => $replacements->approve(InventoryAction::actor(), $this->reportRecord()));
                    $this->reportRecord()->refresh();

                    Notification::make()->success()->title('Đã duyệt Đổi hàng.')->send();
                }),
            Action::make('decline')
                ->label('Không đổi')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->modalHeading('Không đổi')
                ->modalDescription('Kho không xử lý hoàn tiền. Khách được hoàn tiền ngoài kho thì Giá bán của Dòng xuất cần sửa xuống số tiền shop thực giữ.')
                ->modalSubmitActionLabel('Đặt Không đổi')
                ->schema([
                    Textarea::make('reason')
                        ->label('Lý do')
                        ->rows(2)
                        ->required(),
                    Checkbox::make('refunded')
                        ->label('Khách đã được hoàn tiền ngoài kho'),
                ])
                ->visible(fn (DefectReporting $reports): bool => $reports->canDeclineReplacement(InventoryAction::actor(), $this->reportRecord()))
                ->action(function (Action $action, DefectReporting $reports, array $data): void {
                    $refunded = (bool) ($data['refunded'] ?? false);

                    InventoryAction::attempt($action, fn () => $reports->declineReplacement(InventoryAction::actor(), $this->reportRecord(), (string) $data['reason'], $refunded));
                    $this->reportRecord()->refresh();

                    if (! $refunded) {
                        Notification::make()->success()->title('Đã đặt Không đổi.')->send();

                        return;
                    }

                    // Giá bán sửa qua Sửa phiếu của Phiếu xuất để có lịch sử sửa phiếu.
                    Notification::make()
                        ->success()
                        ->title('Đã đặt Không đổi. Hãy sửa Giá bán của Dòng xuất xuống số tiền shop thực giữ.')
                        ->actions([
                            Action::make('openDispatch')
                                ->label('Mở Phiếu xuất để Sửa phiếu')
                                ->url(DispatchResource::getUrl('view', ['record' => $this->reportRecord()->delivery->dispatchLine->dispatch_id])),
                        ])
                        ->persistent()
                        ->send();
                }),
            Action::make('confirmAffected')
                ->label('Báo lỗi hàng loạt')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('warning')
                ->modalHeading('Báo lỗi hàng loạt cho Lần giao bị ảnh hưởng')
                ->modalSubmitActionLabel('Tạo Báo lỗi tự Xác nhận')
                ->schema(fn (DefectReporting $reports): array => $this->affectedSchema($reports->reportableAffectedDeliveries(InventoryAction::actor(), $this->reportRecord())))
                ->visible(fn (DefectReporting $reports): bool => $reports->reportableAffectedDeliveries(InventoryAction::actor(), $this->reportRecord()) !== [])
                ->action(function (Action $action, DefectReporting $reports, array $data): void {
                    $deliveries = Delivery::query()->whereKey(array_map('intval', (array) ($data['deliveries'] ?? [])))->orderBy('id')->get()->all();
                    $created = InventoryAction::attempt($action, fn () => $reports->confirmAffected(InventoryAction::actor(), $this->reportRecord(), $deliveries, $data['override_reason'] ?? null));

                    Notification::make()->success()->title(sprintf('Đã tạo %d Báo lỗi tự Xác nhận.', count($created)))->send();
                }),
        ];
    }

    public function revealedContentAction(): Action
    {
        return Action::make('revealedContent')
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
     * Form Đổi hàng: tóm tắt Hạn bảo hành kế thừa, lần đổi thứ mấy và Slot sẽ chọn cho Sản phẩm đang
     * chọn; Sản phẩm khác bắt buộc lý do; không có Slot phủ Hạn bảo hành thì phải chấp nhận Slot hạn
     * ngắn hơn.
     *
     * @return list<mixed>
     */
    private function replacementSchema(): array
    {
        $report = $this->reportRecord();
        $productId = $report->stockUnit->product_id;
        $replacements = app(ReplacementDelivery::class);
        $preview = fn (Get $get): ReplacementPreview => $replacements->preview(InventoryAction::actor(), $report, Product::query()->find($get('product_id')));
        $changed = fn (Get $get): bool => (int) $get('product_id') !== $productId;

        return [
            Text::make(fn (Get $get): HtmlString => self::previewSummary($preview($get), $report)),
            Select::make('product_id')
                ->label('Sản phẩm giao ra')
                ->options(fn (): array => $replacements->selectableProducts($report)
                    ->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->code} · {$product->name}"])
                    ->all())
                ->searchable()
                ->required()
                ->live()
                ->helperText('Mặc định cùng Sản phẩm với Đơn vị hàng lỗi; Sản phẩm khác bắt buộc lý do.'),
            Textarea::make('product_change_reason')
                ->label('Lý do đổi sang Sản phẩm khác')
                ->rows(2)
                ->required($changed)
                ->visible($changed),
            Checkbox::make('accept_shorter_expiry')
                ->label('Chấp nhận Slot có Hạn sử dụng ngắn hơn Hạn bảo hành kế thừa')
                ->accepted()
                ->visible(fn (Get $get): bool => $preview($get)->availability === ReplacementAvailability::ShorterOnly),
        ];
    }

    private static function previewSummary(ReplacementPreview $preview, DefectReport $report): HtmlString
    {
        $expiresOn = $preview->candidateExpiresOn?->format(DeliveryTemplate::DATE_FORMAT);
        $slot = match ($preview->availability) {
            ReplacementAvailability::OutOfStock => 'Hết hàng: không có Slot nào để Đổi hàng.',
            ReplacementAvailability::Covering => $expiresOn === null ? 'Slot sẽ chọn không có Hạn sử dụng.' : "Slot sẽ chọn có Hạn sử dụng {$expiresOn}.",
            ReplacementAvailability::ShorterOnly => "<strong>Cảnh báo:</strong> không có Slot nào có Hạn sử dụng phủ Hạn bảo hành; Slot hạn dài nhất hết hạn {$expiresOn}.",
        };
        $approval = match (true) {
            ! $preview->requiresApproval => '',
            $report->replacement_approved_at !== null => ' Quản trị đã duyệt lần đổi này.',
            default => ' <strong>Cảnh báo:</strong> từ lần đổi thứ '.ReplacementDelivery::APPROVAL_SEQUENCE.' cần Quản trị duyệt.',
        };

        return new HtmlString(sprintf(
            'Thay cho <strong>%s</strong> · %s. Hạn bảo hành kế thừa: %s. Lần đổi thứ %d trong chuỗi.%s<br>%s',
            e($preview->productName),
            e($preview->unitLabel),
            $preview->warrantyEndsOn->format(DeliveryTemplate::DATE_FORMAT),
            $preview->sequence,
            $approval,
            $slot,
        ));
    }

    /**
     * Form Báo lỗi hàng loạt: chọn Lần giao bị ảnh hưởng; Quản trị nhập lý do khi có lần giao ngoài
     * Hạn bảo hành.
     *
     * @param  list<AffectedDelivery>  $affected
     * @return list<mixed>
     */
    private function affectedSchema(array $affected): array
    {
        return [
            Text::make('Mỗi lần giao được chọn có một Báo lỗi tự Xác nhận cả Đơn vị hàng, cùng mô tả với Báo lỗi này. Hãy liên hệ khách; hệ thống không tự Đổi hàng.'),
            CheckboxList::make('deliveries')
                ->label('Lần giao bị ảnh hưởng')
                ->options(collect($affected)->mapWithKeys(fn (AffectedDelivery $delivery): array => [
                    (string) $delivery->deliveryId => $delivery->label(),
                ])->all())
                ->required(),
            Textarea::make('override_reason')
                ->label('Lý do vượt Hạn bảo hành')
                ->helperText('Chỉ lưu cho lần giao ngoài Hạn bảo hành.')
                ->rows(2)
                // Không truyền Vai trò nào: chỉ Quản trị vượt Hạn bảo hành.
                ->visible(app(RoleGate::class)->allows(InventoryAction::actor())),
        ];
    }

    private function reportRecord(): DefectReport
    {
        $record = $this->getRecord();
        assert($record instanceof DefectReport);

        return $record;
    }
}
