<?php

namespace App\Filament\Resources\Batches;

use App\Filament\Support\InventoryAction;
use App\Inventory\Intake\BatchIntake;
use App\Inventory\Intake\BatchLineRevision;
use App\Inventory\Intake\BatchRevision;
use App\Inventory\Intake\BatchStatus;
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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Hai thao tác của Lô nhập còn Chờ xác nhận — Xác nhận nhập kho và Sửa — khai đúng một lần ở đây,
 * vì trang xem bày chúng ở hai chỗ: thanh nút trên đầu trang và dải quyết định trong thân trang.
 * Khai hai lần là hai đường xác nhận lệch nhau chỉ chờ ngày lệch thật.
 *
 * Lô nhập lấy qua $record được inject (Action.php:572) chứ không qua $this, vì
 * BatchResource::infolist() là static nên không có Page nào để hỏi.
 *
 * Nút trong thân trang mang tên riêng (confirmInline / reviseInline): cacheAction() keyed theo tên
 * nên hai action trùng tên trên một trang là xung đột âm thầm.
 */
final class BatchActions
{
    public static function confirm(string $name = 'confirm'): Action
    {
        return Action::make($name)
            ->label('Xác nhận nhập kho')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Xác nhận nhập kho')
            ->modalDescription(fn (Batch $record): string => sprintf(
                'Chỉ %s Đơn vị hàng nhập được vào kho; dòng lỗi và dòng trùng bị bỏ. Lô nhập đã xác nhận thì đóng.',
                number_format(BatchResource::preview($record)->importCount(), 0, ',', '.'),
            ))
            ->schema([
                Checkbox::make('skip_stock_duplicates')
                    ->label(fn (Batch $record): string => sprintf(
                        'Tôi xác nhận bỏ qua %s dòng trùng trong kho.',
                        number_format(BatchResource::preview($record)->stockDuplicateCount(), 0, ',', '.'),
                    ))
                    ->accepted()
                    ->visible(fn (Batch $record): bool => BatchResource::preview($record)->stockDuplicateCount() > 0),
            ])
            ->visible(fn (Batch $record): bool => $record->status === BatchStatus::Validated
                && InventoryAction::actor()->can('confirm', $record))
            ->action(function (Action $action, BatchIntake $intake, array $data, Batch $record): void {
                InventoryAction::attempt($action, fn () => $intake->confirm(
                    InventoryAction::actor(),
                    $record,
                    skipStockDuplicates: (bool) ($data['skip_stock_duplicates'] ?? false),
                ));
                $record->refresh();
                BatchResource::forgetPreview($record);

                Notification::make()->success()->title('Đã nhập kho phần hợp lệ của Lô nhập.')->send();
            });
    }

    /**
     * Cửa sổ giữa Chờ xác nhận và Xác nhận là lúc nhân viên phát hiện mình gõ sai: sửa được Giá trị
     * áp cho Đơn vị hàng và chứng từ, rồi kiểm tra lại, thay vì bỏ cả lô và dán lại nội dung.
     * Xem ADR 0007.
     */
    public static function revise(string $name = 'revise'): Action
    {
        return Action::make($name)
            ->label('Sửa Lô nhập')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Sửa Lô nhập')
            ->modalDescription('Nội dung (danh sách Đơn vị hàng) giữ nguyên, không phải dán lại. Lưu xong Lô nhập được kiểm tra lại từ đầu; hạn 24 giờ của bản kiểm tra vẫn tính từ lúc tạo Lô nhập.')
            ->modalSubmitActionLabel('Lưu và kiểm tra lại')
            ->fillForm(fn (Batch $record): array => [
                'received_on' => $record->received_on,
                'document_number' => $record->document_number,
                'note' => $record->note,
                'lines' => $record->lines->map(fn (BatchLine $line): array => [
                    'id' => $line->id,
                    'unit_cost' => $line->unit_cost,
                    'slots' => $line->slots,
                    ...BatchResource::expiryState($line->expires_on, $line->expires_after_days),
                ])->all(),
            ])
            // schema() nhận Closure (HasSchema.php:26), nên $record capture được cho cả các closure
            // lồng bên trong — chỗ duy nhất làm được việc này khi không có $this.
            ->schema(fn (Batch $record): array => [
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
                    ->itemLabel(fn (array $state): ?string => self::lineOf($record, $state['id'] ?? null)?->product->name)
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
                            ->visible($record->supplier_claim_id === null),
                        ...BatchResource::unitValueFields(fn (Get $get): ?Product => self::lineOf($record, $get('id'))?->product),
                        // Tầng cột file thắng tầng Dòng nhập, nên với Dòng nhập nhập từ file, sửa ở
                        // đây không cứu được con số gõ nhầm nằm trong file.
                        Text::make('Dòng nhập này nhập từ file: cột slot, han_su_dung, gia_von trong file (nếu có) vẫn thắng các giá trị ở đây.')
                            ->visible(fn (Get $get): bool => match (self::lineOf($record, $get('id'))?->source) {
                                null, IntakeSource::Paste => false,
                                default => true,
                            })
                            ->columnSpanFull(),
                    ]),
            ])
            ->visible(fn (Batch $record): bool => $record->status === BatchStatus::Validated
                && InventoryAction::actor()->can('revise', $record))
            ->action(function (Action $action, BatchIntake $intake, array $data, Batch $record): void {
                InventoryAction::attempt($action, fn () => $intake->revise(
                    InventoryAction::actor(),
                    $record,
                    self::revision($record, $data),
                ));
                $record->refresh();
                BatchResource::forgetPreview($record);

                Notification::make()->success()->title('Đã sửa Lô nhập và kiểm tra lại.')->send();
            });
    }

    /**
     * Lần sửa nhân viên vừa gửi, ghép từ state của form.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidBatch
     */
    private static function revision(Batch $batch, array $data): BatchRevision
    {
        $existing = $batch->lines->keyBy('id');
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
    private static function lineOf(Batch $batch, mixed $lineId): ?BatchLine
    {
        return blank($lineId) ? null : $batch->lines->firstWhere('id', (int) $lineId);
    }
}
