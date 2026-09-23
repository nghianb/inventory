<?php

namespace App\Filament\Resources\Dispatches\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\Clipboard;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\DeliveredContent;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Dispatch\DispatchResultFormat;
use App\Inventory\Reveal\ContentReveal;
use App\Models\Dispatch;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Màn kết quả ngay sau khi xuất kho. Lần hiển thị đầu gọi ContentReveal; tải lại trang thì không
 * hiện nội dung nữa. Module Kho quyết định che hay hiện:
 *
 * - Dưới ngưỡng: lưới thẻ mỗi Slot với tin nhắn theo Mẫu giao hàng, Copy từng Slot và Copy tất cả
 *   chạy ở trình duyệt, không ghi nhật ký. Nội dung không lưu ở server nhưng nằm trong trang và
 *   snapshot Livewire (không mã hoá) tới trình duyệt.
 * - Từ ngưỡng trở lên: chỉ bảng dạng che; Copy tất cả gọi server, ghi nhật ký cho mọi Slot và
 *   chỉ gửi nội dung trong phản hồi của lần bấm đó.
 *
 * Tải TXT/CSV luôn gọi server, sinh lúc tải và ghi nhật ký cho mọi Slot.
 */
class DispatchResult extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DispatchResource::class;

    protected static ?string $title = 'Kết quả xuất kho';

    /**
     * @var list<array{product: string, stock_unit: int, slot: int, fields: array<string, string>, message: ?string, expires_on: ?string, warranty_ends_on: string}>
     */
    #[Locked]
    public array $delivered = [];

    #[Locked]
    public bool $masked = false;

    /**
     * Nội dung Copy tất cả khi hiện đầy đủ, cùng nguồn với các thẻ.
     */
    #[Locked]
    public string $copyAll = '';

    #[Locked]
    public ?string $unavailable = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(DispatchResource::canView($this->getRecord()), 403);

        try {
            $result = app(ContentReveal::class)->revealDispatchResult(InventoryAction::actor(), $this->dispatchRecord());
        } catch (Throwable $exception) {
            if (! InventoryAction::isBusinessError($exception)) {
                throw $exception;
            }

            $this->unavailable = $exception->getMessage();

            return;
        }

        $this->masked = $result->masked;
        $this->delivered = array_map(fn (DeliveredContent $slot): array => [
            'product' => $slot->productName,
            'stock_unit' => $slot->stockUnitId,
            'slot' => $slot->slotId,
            'fields' => $slot->fields,
            'message' => $slot->message,
            'expires_on' => $slot->expiresOn?->format(DeliveryTemplate::DATE_FORMAT),
            'warranty_ends_on' => $slot->warrantyEndsOn->format(DeliveryTemplate::DATE_FORMAT),
        ], $result->slots);
        $this->copyAll = $result->masked ? '' : DeliveredContent::copyAll($result->slots);
    }

    public function content(Schema $schema): Schema
    {
        $dispatch = $this->dispatchRecord();
        $minutes = (int) config('inventory.dispatch.result_download_minutes');

        return $schema->components([
            $this->unavailable === null
                ? Callout::make(sprintf(
                    '%s thành công · %s Slot',
                    $dispatch->result_from_line_id === null ? 'Xuất kho' : 'Giao thêm',
                    DispatchResource::count(count($this->delivered)),
                ))
                    ->success()
                    ->description("Phiếu xuất #{$dispatch->id} · {$dispatch->salesChannel->name} · mã đơn {$dispatch->external_ref}")
                : Callout::make('Không hiện được nội dung')
                    ->warning()
                    ->description($this->unavailable),
            Actions::make([
                $this->masked
                    ? Action::make('copyAll')
                        ->label('Copy tất cả')
                        ->icon(Heroicon::OutlinedClipboardDocument)
                        ->action(function (Action $action, ContentReveal $reveal): void {
                            $text = InventoryAction::attempt($action, fn (): string => $reveal->copyAllDispatchResult(InventoryAction::actor(), $this->dispatchRecord()));

                            $this->js(Clipboard::copy($text, sprintf('Đã copy %s Slot.', DispatchResource::count(count($this->delivered)))));
                        })
                        ->visible($this->delivered !== [])
                    : Action::make('copyAll')
                        ->label('Copy tất cả')
                        ->icon(Heroicon::OutlinedClipboardDocument)
                        ->alpineClickHandler(Clipboard::copy($this->copyAll, 'Đã copy tất cả Slot.'))
                        ->visible($this->delivered !== []),
                ...array_map(fn (DispatchResultFormat $format): Action => Action::make("download{$format->label()}")
                    ->label("Tải {$format->label()}")
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(function (Action $action, ContentReveal $reveal) use ($format): StreamedResponse {
                        $export = InventoryAction::attempt($action, fn () => $reveal->exportDispatchResult(InventoryAction::actor(), $this->dispatchRecord(), $format));

                        return response()->streamDownload(fn () => print ($export->contents), $export->fileName, ['Content-Type' => $export->contentType]);
                    })
                    ->visible($this->delivered !== []), DispatchResultFormat::cases()),
                Action::make('done')
                    ->label('Xong, về phiếu')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('gray')
                    ->url(DispatchResource::getUrl('view', ['record' => $dispatch])),
            ])->key('resultActions'),
            $this->masked ? $this->maskedTable() : $this->cards(),
            Text::make($this->masked
                ? 'Phiếu từ '.DispatchResource::count((int) config('inventory.dispatch.result_mask_slots'))." Slot trở lên chỉ hiện dạng che. Copy tất cả và Tải file lấy nội dung đầy đủ trong {$minutes} phút, mỗi lần đều ghi Nhật ký xem mã cho mọi Slot."
                : "Nội dung chỉ hiện ở màn này một lần: hãy copy trước khi rời hoặc tải lại trang. Tải file được trong {$minutes} phút, mỗi lần tải ghi Nhật ký xem mã.")
                ->visible($this->delivered !== []),
        ]);
    }

    private function cards(): Component
    {
        return Grid::make(['default' => 1, 'md' => 2])
            ->schema(array_map(fn (array $slot, int $index): Section => Section::make(sprintf('#%d %s', $index + 1, $slot['product']))
                ->description("Đơn vị hàng #{$slot['stock_unit']} · Slot #{$slot['slot']}")
                ->compact()
                ->headerActions([
                    Action::make("copy{$index}")
                        ->label('Copy')
                        ->icon(Heroicon::OutlinedClipboard)
                        ->color('gray')
                        ->size('sm')
                        ->alpineClickHandler(Clipboard::copy((string) $slot['message'], sprintf('Đã copy Slot #%d.', $index + 1))),
                ])
                ->schema([
                    TextEntry::make("slot{$index}")
                        ->hiddenLabel()
                        ->state($slot['message'])
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))
                        ->fontFamily(FontFamily::Mono),
                ]), $this->delivered, array_keys($this->delivered)));
    }

    private function maskedTable(): Component
    {
        return RepeatableEntry::make('masked_rows')
            ->label('Lần giao (đã che)')
            ->state(array_map(fn (array $slot, int $index): array => [
                'index' => $index + 1,
                'product' => $slot['product'],
                'unit' => "#{$slot['stock_unit']} · Slot #{$slot['slot']}",
                'content' => collect($slot['fields'])->map(fn (string $value, string $label): string => "{$label}: {$value}")->implode(' · '),
                'expires_on' => $slot['expires_on'],
                'warranty_ends_on' => $slot['warranty_ends_on'],
            ], $this->delivered, array_keys($this->delivered)))
            ->table([
                TableColumn::make('#'),
                TableColumn::make('Sản phẩm'),
                TableColumn::make('Đơn vị hàng'),
                TableColumn::make('Nội dung (đã che)'),
                TableColumn::make('Hạn sử dụng'),
                TableColumn::make('Hạn bảo hành'),
            ])
            ->schema([
                TextEntry::make('index'),
                TextEntry::make('product'),
                TextEntry::make('unit'),
                TextEntry::make('content')->fontFamily(FontFamily::Mono),
                TextEntry::make('expires_on')->placeholder('Không thời hạn'),
                TextEntry::make('warranty_ends_on'),
            ])
            ->columnSpanFull();
    }

    private function dispatchRecord(): Dispatch
    {
        $record = $this->getRecord();
        assert($record instanceof Dispatch);

        return $record;
    }
}
