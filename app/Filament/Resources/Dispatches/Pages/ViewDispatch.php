<?php

namespace App\Filament\Resources\Dispatches\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\DispatchEdit;
use App\Inventory\Dispatch\DispatchEditor;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\ExternalRefs;
use App\Inventory\Dispatch\ManualDispatch;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Trang xem Phiếu xuất: thông tin đơn, Dòng xuất, Lần giao ở dạng che (Xem mã từng lần giao) và
 * Lịch sử sửa phiếu. Sửa phiếu Hoàn tất qua modal, gọi DispatchEditor.
 */
class ViewDispatch extends ViewRecord
{
    protected static string $resource = DispatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('additional')
                ->label('Giao thêm')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->url(fn (): string => DispatchResource::getUrl('create', [CreateDispatch::ADDITIONAL_QUERY => $this->dispatchRecord()->id]))
                ->visible(fn (ManualDispatch $manual): bool => $manual->canAddLines(InventoryAction::actor(), $this->dispatchRecord())),
            Action::make('edit')
                ->label('Sửa phiếu')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modalHeading('Sửa Phiếu xuất')
                ->modalDescription('Không sửa được Kênh bán, Dòng xuất và Slot. Mỗi lần sửa ghi vào Lịch sử sửa phiếu.')
                ->modalSubmitActionLabel('Lưu')
                ->fillForm(fn (): array => [
                    'external_ref' => $this->dispatchRecord()->external_ref,
                    'customer' => $this->dispatchRecord()->customer,
                    'note' => $this->dispatchRecord()->note,
                    'sale_prices' => $this->dispatchRecord()->lines->mapWithKeys(fn (DispatchLine $line): array => [self::salePriceKey($line) => $line->sale_price])->all(),
                ])
                ->schema(fn (): array => [
                    TextInput::make('external_ref')
                        ->label('Mã đơn ngoài')
                        ->required()
                        ->maxLength(ExternalRefs::MAX_LENGTH),
                    Textarea::make('customer')
                        ->label('Khách')
                        ->rows(2),
                    TextInput::make('note')
                        ->label('Ghi chú')
                        ->maxLength(1000),
                    Section::make('Giá bán (tổng dòng)')
                        ->compact()
                        ->schema($this->dispatchRecord()->lines->map(fn (DispatchLine $line): TextInput => TextInput::make('sale_prices.'.self::salePriceKey($line))
                            ->label("{$line->product->name} · {$line->kind->label()} · {$line->quantity} Slot")
                            ->placeholder('Chưa có')
                            ->suffix('₫')
                            ->integer()
                            ->minValue(0)
                            // Dòng xuất loại Đổi hàng không có Giá bán: Chi phí đổi hàng không vào Lãi gộp.
                            ->hidden($line->kind === DispatchLineKind::Replacement))->all()),
                ])
                ->visible(fn (DispatchEditor $editor): bool => $editor->canEdit(InventoryAction::actor(), $this->dispatchRecord()))
                ->action(function (Action $action, DispatchEditor $editor, array $data): void {
                    $prices = (array) ($data['sale_prices'] ?? []);

                    InventoryAction::attempt($action, fn () => $editor->edit(InventoryAction::actor(), $this->dispatchRecord(), new DispatchEdit(
                        externalRef: $data['external_ref'] ?? null,
                        customer: $data['customer'] ?? null,
                        note: $data['note'] ?? null,
                        salePrices: $this->dispatchRecord()->lines->mapWithKeys(fn (DispatchLine $line): array => [
                            $line->id => filled($prices[self::salePriceKey($line)] ?? null) ? (int) $prices[self::salePriceKey($line)] : null,
                        ])->all(),
                    )));
                    $this->dispatchRecord()->refresh();

                    Notification::make()->success()->title('Đã sửa Phiếu xuất.')->send();
                }),
        ];
    }

    /**
     * Khoá trường Giá bán của một Dòng xuất trong form sửa phiếu.
     */
    private static function salePriceKey(DispatchLine $line): string
    {
        return "line_{$line->id}";
    }

    private function dispatchRecord(): Dispatch
    {
        $record = $this->getRecord();
        assert($record instanceof Dispatch);

        return $record;
    }
}
