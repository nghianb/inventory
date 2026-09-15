<?php

namespace App\Filament\Resources\Dispatches\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\DeliveredContent;
use App\Inventory\Reveal\ContentReveal;
use App\Models\Dispatch;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Màn kết quả ngay sau khi xuất kho: lưới thẻ mỗi Slot với nội dung ghép Mẫu giao hàng, Copy
 * từng Slot và Copy tất cả (chạy ở trình duyệt, không ghi nhật ký). Lần hiển thị đầu gọi
 * ContentReveal, nơi ghi Nhật ký xem mã; tải lại trang thì không hiện nội dung nữa. Nội dung
 * không lưu ở server nhưng nằm trong trang và snapshot Livewire (không mã hoá) tới trình duyệt.
 */
class DispatchResult extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DispatchResource::class;

    protected static ?string $title = 'Kết quả xuất kho';

    /**
     * @var list<array{product: string, stock_unit: int, slot: int, message: string}>
     */
    #[Locked]
    public array $delivered = [];

    /**
     * Nội dung Copy tất cả, cùng nguồn với các thẻ.
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

        $this->delivered = array_map(fn (DeliveredContent $slot): array => [
            'product' => $slot->productName,
            'stock_unit' => $slot->stockUnitId,
            'slot' => $slot->slotId,
            'message' => $slot->message,
        ], $result);
        $this->copyAll = DeliveredContent::copyAll($result);
    }

    public function content(Schema $schema): Schema
    {
        $dispatch = $this->dispatchRecord();

        return $schema->components([
            $this->unavailable === null
                ? Callout::make(sprintf('Xuất kho thành công · %s Slot', DispatchResource::count(count($this->delivered))))
                    ->success()
                    ->description("Phiếu xuất #{$dispatch->id} · {$dispatch->salesChannel->name} · mã đơn {$dispatch->external_ref}")
                : Callout::make('Không hiện được nội dung')
                    ->warning()
                    ->description($this->unavailable),
            Actions::make([
                Action::make('copyAll')
                    ->label('Copy tất cả')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->alpineClickHandler(self::copyHandler($this->copyAll, 'Đã copy tất cả Slot.'))
                    ->visible($this->delivered !== []),
                Action::make('done')
                    ->label('Xong, về phiếu')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('gray')
                    ->url(DispatchResource::getUrl('view', ['record' => $dispatch])),
            ]),
            Grid::make(['default' => 1, 'md' => 2])
                ->schema(array_map(fn (array $slot, int $index): Section => Section::make(sprintf('#%d %s', $index + 1, $slot['product']))
                    ->description("Đơn vị hàng #{$slot['stock_unit']} · Slot #{$slot['slot']}")
                    ->compact()
                    ->headerActions([
                        Action::make("copy{$index}")
                            ->label('Copy')
                            ->icon(Heroicon::OutlinedClipboard)
                            ->color('gray')
                            ->size('sm')
                            ->alpineClickHandler(self::copyHandler($slot['message'], sprintf('Đã copy Slot #%d.', $index + 1))),
                    ])
                    ->schema([
                        TextEntry::make("slot{$index}")
                            ->hiddenLabel()
                            ->state($slot['message'])
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))
                            ->fontFamily(FontFamily::Mono),
                    ]), $this->delivered, array_keys($this->delivered))),
            Text::make('Nội dung chỉ hiện ở màn này một lần: hãy copy trước khi rời hoặc tải lại trang.')
                ->visible($this->delivered !== []),
        ]);
    }

    /**
     * Copy vào clipboard ở trình duyệt, không gọi server nên không ghi Nhật ký xem mã.
     */
    private static function copyHandler(string $text, string $notification): string
    {
        return sprintf(
            'window.navigator.clipboard.writeText(%s).then(() => new FilamentNotification().title(%s).success().send())',
            Js::from($text),
            Js::from($notification),
        );
    }

    private function dispatchRecord(): Dispatch
    {
        $record = $this->getRecord();
        assert($record instanceof Dispatch);

        return $record;
    }
}
