<?php

namespace App\Filament\Resources\DefectReports\Pages;

use App\Filament\Resources\DefectReports\DefectReportResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Reveal\ContentReveal;
use App\Inventory\Warranty\DefectReporting;
use App\Inventory\Warranty\DefectScope;
use App\Models\DefectReport;
use App\Models\Delivery;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Trang xác minh Báo lỗi: Xem mã (ghi Nhật ký xem mã ngữ cảnh Báo lỗi), Xác nhận với Phạm vi lỗi
 * hoặc Bác bỏ, kèm ghi chú. Đơn vị hàng chuyển Lỗi thì liệt kê Lần giao bị ảnh hưởng và cho tạo Báo
 * lỗi hàng loạt tự Xác nhận. Nội dung chỉ nằm trong tham số của modal vừa mở.
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
