<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineRevision;
use App\Inventory\Intake\BatchRevision;
use App\Inventory\Intake\BatchStatus;
use App\Inventory\Intake\ImportReversal;
use App\Inventory\Intake\IntakeSource;
use App\Inventory\Intake\InvalidBatch;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Màn xem trước và kết quả của Lô nhập.
 */
class ViewBatch extends ViewRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        $stockDuplicates = fn (BatchIntake $intake): int => $intake->preview(InventoryAction::actor(), $this->batch())->stockDuplicateCount();

        return [
            Action::make('refresh')
                ->label('Làm mới')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Validating)
                ->action(fn () => $this->batch()->refresh()),
            Action::make('confirm')
                ->label('Xác nhận nhập kho')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (BatchIntake $intake): string => sprintf(
                    'Chỉ %s Đơn vị hàng nhập được vào kho; dòng lỗi và dòng trùng bị bỏ. Lô nhập đã xác nhận thì đóng.',
                    number_format($intake->preview(InventoryAction::actor(), $this->batch())->importCount(), 0, ',', '.'),
                ))
                ->schema([
                    Checkbox::make('skip_stock_duplicates')
                        ->label(fn (BatchIntake $intake): string => sprintf(
                            'Tôi xác nhận bỏ qua %s dòng trùng trong kho.',
                            number_format($stockDuplicates($intake), 0, ',', '.'),
                        ))
                        ->accepted()
                        ->visible(fn (BatchIntake $intake): bool => $stockDuplicates($intake) > 0),
                ])
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Validated
                    && InventoryAction::actor()->can('confirm', $this->batch()))
                ->action(function (Action $action, BatchIntake $intake, array $data): void {
                    InventoryAction::attempt($action, fn () => $intake->confirm(
                        InventoryAction::actor(),
                        $this->batch(),
                        skipStockDuplicates: (bool) ($data['skip_stock_duplicates'] ?? false),
                    ));
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã nhập kho phần hợp lệ của Lô nhập.')->send();
                }),
            // Cửa sổ giữa Chờ xác nhận và Xác nhận là lúc nhân viên phát hiện mình gõ sai: sửa
            // được Giá trị áp cho Đơn vị hàng và chứng từ, rồi kiểm tra lại, thay vì bỏ cả lô và
            // dán lại nội dung. Xem ADR 0007.
            Action::make('revise')
                ->label('Sửa Lô nhập')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->modalHeading('Sửa Lô nhập')
                ->modalDescription('Nội dung (danh sách Đơn vị hàng) giữ nguyên, không phải dán lại. Lưu xong Lô nhập được kiểm tra lại từ đầu; hạn 24 giờ của bản kiểm tra vẫn tính từ lúc tạo Lô nhập.')
                ->modalSubmitActionLabel('Lưu và kiểm tra lại')
                ->fillForm(fn (): array => [
                    'received_on' => $this->batch()->received_on,
                    'document_number' => $this->batch()->document_number,
                    'note' => $this->batch()->note,
                    'lines' => $this->batch()->lines->map(fn (BatchLine $line): array => [
                        'id' => $line->id,
                        'unit_cost' => $line->unit_cost,
                        'slots' => $line->slots,
                        ...BatchResource::expiryState($line->expires_on, $line->expires_after_days),
                    ])->all(),
                ])
                ->schema([
                    DatePicker::make('received_on')
                        ->label('Ngày nhập')
                        ->helperText('Mốc mà Hạn sử dụng khai theo số ngày quy về.')
                        ->required(),
                    TextInput::make('document_number')
                        ->label('Số chứng từ')
                        ->maxLength(255),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->rows(2),
                    Repeater::make('lines')
                        ->label('Dòng nhập')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => $this->lineOf($state['id'] ?? null)?->product->name)
                        ->columns(2)
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('unit_cost')
                                ->label('Giá vốn mỗi Đơn vị hàng')
                                ->suffix('₫')
                                ->integer()
                                ->minValue(0)
                                ->required()
                                // Hàng thay thế từ Khiếu nại luôn Giá vốn 0, như form tạo.
                                ->visible(fn (): bool => $this->batch()->supplier_claim_id === null),
                            ...BatchResource::unitValueFields(fn (Get $get): ?Product => $this->lineOf($get('id'))?->product),
                            // Tầng cột file thắng tầng Dòng nhập, nên với Dòng nhập nhập từ file,
                            // sửa ở đây không cứu được con số gõ nhầm nằm trong file.
                            Text::make('Dòng nhập này nhập từ file: cột slot, han_su_dung, gia_von trong file (nếu có) vẫn thắng các giá trị ở đây.')
                                ->visible(fn (Get $get): bool => match ($this->lineOf($get('id'))?->source) {
                                    null, IntakeSource::Paste => false,
                                    default => true,
                                })
                                ->columnSpanFull(),
                        ]),
                ])
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Validated
                    && InventoryAction::actor()->can('revise', $this->batch()))
                ->action(function (Action $action, BatchIntake $intake, array $data): void {
                    InventoryAction::attempt($action, fn () => $intake->revise(
                        InventoryAction::actor(),
                        $this->batch(),
                        $this->revision($data),
                    ));
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã sửa Lô nhập và kiểm tra lại.')->send();
                }),
            // Ngoại lệ cố ý duy nhất sửa được sau khi xác nhận: hoá đơn hay về sau hàng, và con
            // số này không đẻ ra Giá vốn hay báo cáo nào. Xem ADR 0006.
            Action::make('invoiceTotal')
                ->label('Tổng tiền hoá đơn')
                ->color('gray')
                ->modalDescription('Hoá đơn thường về sau hàng, nên ghi được cả khi Lô nhập đã Xác nhận. Con số này chỉ để đối chiếu với Tổng Giá vốn: nó không phải Giá vốn và không vào báo cáo nào.')
                ->modalSubmitActionLabel('Lưu')
                ->fillForm(fn (): array => ['invoice_total' => $this->batch()->invoice_total])
                ->schema([
                    TextInput::make('invoice_total')
                        ->label('Tổng tiền hoá đơn')
                        ->helperText('Để trống nếu chưa có hoá đơn.')
                        ->suffix('₫')
                        ->integer()
                        ->minValue(0),
                ])
                ->visible(fn (): bool => in_array($this->batch()->status, [BatchStatus::Validated, BatchStatus::Confirmed], true)
                    && InventoryAction::actor()->can('recordInvoiceTotal', $this->batch()))
                ->action(function (array $data): void {
                    $this->batch()->forceFill([
                        'invoice_total' => filled($data['invoice_total'] ?? null) ? (int) $data['invoice_total'] : null,
                    ])->save();
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã ghi Tổng tiền hoá đơn.')->send();
                }),
            Action::make('downloadRejected')
                ->label('Tải CSV dòng bị bỏ')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalDescription('Mỗi lần tải được ghi vào Nhật ký xem mã. Chỉ tải được ở màn xem trước hoặc ngay sau khi xác nhận.')
                ->modalSubmitActionLabel('Tải CSV')
                ->schema([
                    Select::make('line')
                        ->label('Dòng nhập')
                        ->options(fn (): array => $this->rejectedLines()->mapWithKeys(fn (BatchLine $line): array => [
                            $line->id => sprintf('%s (%s dòng bị bỏ)', $line->product->name, number_format(count($line->preview['rejected'] ?? []), 0, ',', '.')),
                        ])->all())
                        ->default(fn (): ?int => $this->rejectedLines()->count() === 1 ? $this->rejectedLines()->first()?->id : null)
                        ->selectablePlaceholder(false)
                        ->required(),
                ])
                ->visible(fn (): bool => $this->rejectedLines()->isNotEmpty())
                ->action(function (Action $action, BatchIntake $intake, array $data): StreamedResponse {
                    $export = InventoryAction::attempt($action, fn () => $intake->rejectedLines(
                        InventoryAction::actor(),
                        $this->rejectedLines()->firstOrFail(fn (BatchLine $line): bool => $line->id === (int) $data['line']),
                    ));

                    return response()->streamDownload(fn () => print ($export->csv), $export->fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            Action::make('reverse')
                ->label('Huỷ nhập')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->modalHeading('Huỷ nhập')
                ->modalDescription('Rút lại hàng nhập nhầm như chưa từng vào kho: Khoá chống trùng được giải phóng để nhập lại đúng mã, bản ghi vẫn giữ. Chỉ Đơn vị hàng mà mọi Slot còn Còn hàng bị Huỷ nhập.')
                ->modalSubmitActionLabel('Huỷ nhập')
                ->schema([
                    Select::make('target')
                        ->label('Phạm vi')
                        ->options(fn (): array => ['batch' => 'Cả Lô nhập'] + $this->batch()->lines->mapWithKeys(fn (BatchLine $line): array => [
                            $line->id => "Dòng nhập \"{$line->product->name}\"",
                        ])->all())
                        ->default('batch')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),
                    Text::make(function (Get $get, ImportReversal $reversal): string {
                        $plan = $reversal->plan(InventoryAction::actor(), $this->reversalTarget($get('target')));

                        return sprintf(
                            'Sẽ Huỷ nhập %s Đơn vị hàng (%s Slot). Giữ lại %s Đơn vị hàng có Slot đã giữ, đã giao hoặc không còn Hoạt động.',
                            number_format($plan->reversedUnits, 0, ',', '.'),
                            number_format($plan->reversedSlots, 0, ',', '.'),
                            number_format($plan->keptUnits, 0, ',', '.'),
                        );
                    }),
                    Textarea::make('reason')
                        ->label('Lý do')
                        ->rows(2),
                ])
                ->visible(fn (): bool => $this->batch()->status === BatchStatus::Confirmed
                    && InventoryAction::actor()->can('reverse', $this->batch()))
                ->action(function (Action $action, ImportReversal $reversal, array $data): void {
                    $done = InventoryAction::attempt($action, fn () => $reversal->reverse(
                        InventoryAction::actor(),
                        $this->reversalTarget($data['target'] ?? null),
                        $data['reason'] ?? null,
                    ));
                    $this->batch()->refresh();

                    Notification::make()->success()->title(sprintf(
                        'Đã Huỷ nhập %s Đơn vị hàng; giữ lại %s Đơn vị hàng.',
                        number_format($done->reversedUnits, 0, ',', '.'),
                        number_format($done->keptUnits, 0, ',', '.'),
                    ))->send();
                }),
            Action::make('discard')
                ->label('Bỏ Lô nhập')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Nội dung tạm của Lô nhập bị xoá, không xác nhận được nữa.')
                ->visible(fn (): bool => in_array($this->batch()->status, BatchStatus::pending(), true)
                    && InventoryAction::actor()->can('confirm', $this->batch()))
                ->action(function (Action $action, BatchIntake $intake): void {
                    InventoryAction::attempt($action, fn () => $intake->discard(InventoryAction::actor(), $this->batch()));
                    $this->batch()->refresh();

                    Notification::make()->success()->title('Đã bỏ Lô nhập.')->send();
                }),
        ];
    }

    /**
     * Dòng nhập mà nhân viên đang đăng nhập tải được dòng bị bỏ lúc này.
     *
     * @return Collection<int, BatchLine>
     */
    private function rejectedLines(): Collection
    {
        return app(BatchIntake::class)->downloadableRejectedLines(InventoryAction::actor(), $this->batch());
    }

    /**
     * Phạm vi Huỷ nhập đang chọn: cả Lô nhập hoặc một Dòng nhập của nó.
     */
    private function reversalTarget(mixed $target): Batch|BatchLine
    {
        if ($target === null || $target === 'batch') {
            return $this->batch();
        }

        return $this->batch()->lines->firstOrFail(fn (BatchLine $line): bool => $line->id === (int) $target);
    }

    /**
     * Lần sửa nhân viên vừa gửi, ghép từ state của form.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidBatch
     */
    private function revision(array $data): BatchRevision
    {
        $existing = $this->batch()->lines->keyBy('id');
        $revisions = [];

        foreach ($data['lines'] ?? [] as $line) {
            $revisions[] = new BatchLineRevision(
                line: $existing->get((int) $line['id']) ?? throw new InvalidBatch('Dòng nhập không thuộc Lô nhập này.'),
                // Hàng thay thế từ Khiếu nại: form ẩn Giá vốn, mà Filament loại field bị ẩn khỏi state.
                unitCost: (int) ($line['unit_cost'] ?? 0),
                slots: filled($line['slots'] ?? null) ? (int) $line['slots'] : null,
                expiry: BatchResource::expiryRule($line),
            );
        }

        return new BatchRevision(
            receivedOn: CarbonImmutable::parse($data['received_on']),
            lines: $revisions,
            documentNumber: filled($data['document_number'] ?? null) ? (string) $data['document_number'] : null,
            note: filled($data['note'] ?? null) ? (string) $data['note'] : null,
        );
    }

    /**
     * Dòng nhập theo id trong state của form sửa, lấy từ các Dòng nhập đã nạp của Lô nhập: ô này
     * được vẽ lại mỗi lần state đổi nên không truy vấn lại từng lần.
     */
    private function lineOf(mixed $lineId): ?BatchLine
    {
        return blank($lineId) ? null : $this->batch()->lines->firstWhere('id', (int) $lineId);
    }

    private function batch(): Batch
    {
        $record = $this->getRecord();
        assert($record instanceof Batch);

        return $record;
    }
}
