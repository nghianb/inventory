<?php

namespace App\Filament\Resources\SupplierClaims\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\SupplierClaims\SupplierClaimResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Claims\ClaimOutcome;
use App\Inventory\Claims\ClaimOutcomeDraft;
use App\Inventory\Claims\SupplierClaims;
use App\Models\StockUnit;
use App\Models\SupplierClaim;
use App\Models\SupplierClaimUnit;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Chi tiết Khiếu nại nhà cung cấp: thêm Đơn vị hàng vào Nháp, gửi, giải quyết (kết quả từng Đơn vị
 * hàng), huỷ, và mở trang tạo Lô nhập hàng thay thế. Gỡ và Xem mã từng Đơn vị hàng ở bảng bên dưới.
 */
class ViewSupplierClaim extends ViewRecord
{
    protected static string $resource = SupplierClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addUnits')
                ->label('Thêm Đơn vị hàng')
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->modalHeading('Thêm Đơn vị hàng Lỗi')
                ->modalSubmitActionLabel('Thêm')
                ->schema([
                    Select::make('stock_unit_ids')
                        ->label('Đơn vị hàng Lỗi chưa khiếu nại')
                        ->multiple()
                        ->options(fn (): array => SupplierClaimResource::unclaimedOptions($this->claimRecord()->supplier_id))
                        ->searchable()
                        ->required(),
                ])
                ->visible(fn (SupplierClaims $claims): bool => $claims->canEdit(InventoryAction::actor(), $this->claimRecord()))
                ->action(function (Action $action, SupplierClaims $claims, array $data): void {
                    $units = StockUnit::query()->whereKey(array_map('intval', (array) $data['stock_unit_ids']))->orderBy('id')->get()->all();

                    InventoryAction::attempt($action, fn () => $claims->addUnits(InventoryAction::actor(), $this->claimRecord(), $units));
                    $this->refreshClaim();

                    Notification::make()->success()->title('Đã thêm Đơn vị hàng vào Khiếu nại.')->send();
                }),
            Action::make('send')
                ->label('Đánh dấu Đã gửi')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalHeading('Đánh dấu Khiếu nại Đã gửi')
                ->modalDescription('Sau khi gửi cho Nhà cung cấp, Khiếu nại không thêm hay gỡ Đơn vị hàng được nữa.')
                ->visible(fn (SupplierClaims $claims): bool => $claims->canSend(InventoryAction::actor(), $this->claimRecord()))
                ->action(function (Action $action, SupplierClaims $claims): void {
                    InventoryAction::attempt($action, fn () => $claims->send(InventoryAction::actor(), $this->claimRecord()));
                    $this->refreshClaim();

                    Notification::make()->success()->title('Đã đánh dấu Khiếu nại Đã gửi.')->send();
                }),
            Action::make('resolve')
                ->label('Giải quyết')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->modalHeading('Giải quyết Khiếu nại')
                ->modalDescription('Ghi kết quả cho từng Đơn vị hàng. Hàng thay thế nhập vào kho bằng Lô nhập liên kết với Khiếu nại, Giá vốn 0.')
                ->modalSubmitActionLabel('Giải quyết')
                ->fillForm(fn (): array => ['outcomes' => $this->claimRecord()->claimUnits()->where('active', true)->with('stockUnit.product.contentFields')->get()
                    ->map(fn (SupplierClaimUnit $row): array => [
                        'claim_unit_id' => $row->id,
                        'unit' => SupplierClaimResource::unitLabel($row->stockUnit),
                        'outcome' => null,
                        'refunded_on' => CarbonImmutable::today()->toDateString(),
                    ])->all()])
                ->schema([
                    Repeater::make('outcomes')
                        ->label('Kết quả')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): string => (string) ($state['unit'] ?? ''))
                        ->columns(3)
                        ->schema([
                            Hidden::make('claim_unit_id'),
                            Hidden::make('unit'),
                            Select::make('outcome')
                                ->label('Kết quả')
                                ->options(ClaimOutcome::options())
                                ->required()
                                ->live(),
                            TextInput::make('refund_amount')
                                ->label('Số tiền bồi hoàn')
                                ->suffix('₫')
                                ->integer()
                                ->minValue(1)
                                ->required()
                                ->visible(fn (Get $get): bool => $get('outcome') === ClaimOutcome::Refund->value),
                            DatePicker::make('refunded_on')
                                ->label('Ngày bồi hoàn')
                                ->maxDate(fn (): CarbonImmutable => CarbonImmutable::today())
                                ->required()
                                ->visible(fn (Get $get): bool => $get('outcome') === ClaimOutcome::Refund->value),
                            Textarea::make('note')
                                ->label('Ghi chú')
                                ->rows(1)
                                ->columnSpanFull(),
                        ]),
                ])
                ->visible(fn (SupplierClaims $claims): bool => $claims->canResolve(InventoryAction::actor(), $this->claimRecord()))
                ->action(function (Action $action, SupplierClaims $claims, array $data): void {
                    $outcomes = [];

                    foreach ((array) ($data['outcomes'] ?? []) as $row) {
                        $outcome = ClaimOutcome::from($row['outcome']);
                        $refund = $outcome === ClaimOutcome::Refund;

                        $outcomes[(int) $row['claim_unit_id']] = new ClaimOutcomeDraft(
                            outcome: $outcome,
                            refundAmount: $refund && filled($row['refund_amount'] ?? null) ? (int) $row['refund_amount'] : null,
                            refundedOn: $refund && filled($row['refunded_on'] ?? null) ? CarbonImmutable::parse($row['refunded_on']) : null,
                            note: $row['note'] ?? null,
                        );
                    }

                    InventoryAction::attempt($action, fn () => $claims->resolve(InventoryAction::actor(), $this->claimRecord(), $outcomes));
                    $this->refreshClaim();

                    Notification::make()->success()->title('Đã giải quyết Khiếu nại.')->send();
                }),
            Action::make('importReplacementGoods')
                ->label('Nhập hàng thay thế')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('success')
                ->url(fn (): string => BatchResource::getUrl('create', [CreateBatch::CLAIM_QUERY => $this->claimRecord()->id]))
                ->visible(fn (SupplierClaims $claims): bool => $claims->canImportReplacementGoods(InventoryAction::actor(), $this->claimRecord())),
            Action::make('cancel')
                ->label('Huỷ Khiếu nại')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->modalHeading('Huỷ Khiếu nại')
                ->modalDescription('Đơn vị hàng quay lại danh sách Lỗi chưa khiếu nại.')
                ->modalSubmitActionLabel('Huỷ Khiếu nại')
                ->schema([
                    Textarea::make('reason')
                        ->label('Lý do')
                        ->rows(2)
                        ->required(),
                ])
                ->visible(fn (SupplierClaims $claims): bool => $claims->canCancel(InventoryAction::actor(), $this->claimRecord()))
                ->action(function (Action $action, SupplierClaims $claims, array $data): void {
                    InventoryAction::attempt($action, fn () => $claims->cancel(InventoryAction::actor(), $this->claimRecord(), (string) $data['reason']));
                    $this->refreshClaim();

                    Notification::make()->success()->title('Đã huỷ Khiếu nại.')->send();
                }),
        ];
    }

    /**
     * Làm mới bản ghi và bảng Đơn vị hàng sau khi đổi trạng thái.
     */
    private function refreshClaim(): void
    {
        $this->claimRecord()->refresh();
        $this->dispatch('refresh-claim-units');
    }

    private function claimRecord(): SupplierClaim
    {
        $record = $this->getRecord();
        assert($record instanceof SupplierClaim);

        return $record;
    }
}
