<?php

namespace App\Filament\Resources\Dispatches\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\DispatchDraft;
use App\Inventory\Dispatch\DispatchLineDraft;
use App\Inventory\Dispatch\DispatchProblem;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\ManualDispatch;
use App\Inventory\Dispatch\Shortage;
use App\Models\Dispatch;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\HtmlString;

/**
 * Tạo Phiếu xuất. Bấm Xuất kho: kiểm tra form và lỗi nghiệp vụ (hiện đầu trang), rồi mở modal
 * xác nhận tóm tắt không có nội dung mã; thiếu hàng thì modal báo từng dòng và không cho giao.
 * Xác nhận thì giao ngay và chuyển sang màn kết quả.
 */
class CreateDispatch extends CreateRecord
{
    protected static string $resource = DispatchResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tạo Phiếu xuất';

    /**
     * Lỗi kiểm tra của lần bấm Xuất kho gần nhất, hiện trong hộp lỗi đầu form.
     *
     * @var list<array{message: string, url: ?string}>
     */
    public array $problems = [];

    private ?DispatchDraft $draft = null;

    /**
     * @var ?list<Shortage>
     */
    private ?array $shortages = null;

    public function create(bool $another = false): void
    {
        $this->authorizeAccess();

        $this->problems = self::problemRows(app(ManualDispatch::class)->check(InventoryAction::actor(), $this->draft()));

        if ($this->problems === []) {
            $this->mountAction('confirmDispatch');
        }
    }

    public function confirmDispatchAction(): Action
    {
        return Action::make('confirmDispatch')
            ->modalHeading('Xác nhận xuất kho')
            ->modalDescription(fn (): string => $this->shortages() === []
                ? 'Slot được chọn tự động theo Thứ tự xuất và giao ngay; nội dung hiện ở màn kết quả.'
                : 'Không đủ hàng nên không giao gì. Quay lại sửa số lượng hoặc bỏ dòng thiếu.')
            ->schema(fn (): array => $this->confirmationSchema())
            ->modalSubmitActionLabel('Xuất kho')
            ->modalSubmitAction(fn (Action $action): Action|false => $this->shortages() === [] ? $action : false)
            ->modalCancelActionLabel(fn (): string => $this->shortages() === [] ? 'Huỷ' : 'Quay lại sửa')
            ->action(function (Action $action): void {
                $dispatch = InventoryAction::attempt($action, function () use ($action): Dispatch {
                    try {
                        return app(ManualDispatch::class)->create(InventoryAction::actor(), $this->draft());
                    } catch (InvalidDispatch $exception) {
                        // Phiếu khác vừa chiếm mã đơn sau lần kiểm tra: đóng modal, đưa lỗi (kèm link phiếu cũ) về đầu form.
                        $this->problems = self::problemRows($exception->problems);
                        $action->cancel();

                        throw $exception;
                    }
                });

                $this->redirect(DispatchResource::getUrl('result', ['record' => $dispatch]));
            });
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Xuất kho');
    }

    /**
     * @param  list<DispatchProblem>  $problems
     * @return list<array{message: string, url: ?string}>
     */
    private static function problemRows(array $problems): array
    {
        return array_map(fn (DispatchProblem $problem): array => [
            'message' => $problem->message,
            'url' => $problem->existingDispatchId === null ? null : DispatchResource::getUrl('view', ['record' => $problem->existingDispatchId]),
        ], $problems);
    }

    /**
     * @return list<Component>
     */
    private function confirmationSchema(): array
    {
        $draft = $this->draft();
        $shortages = $this->shortages();
        $prices = array_values(array_filter(array_map(fn (DispatchLineDraft $line): ?int => $line->salePrice, $draft->lines), fn (?int $price): bool => $price !== null));

        return [
            Callout::make('Không đủ hàng')
                ->danger()
                ->description(new HtmlString(implode('<br>', array_map(fn (Shortage $shortage): string => e(sprintf(
                    '"%s": cần %s, còn %s.',
                    $shortage->productName,
                    DispatchResource::count($shortage->needed),
                    DispatchResource::count($shortage->available),
                )), $shortages))))
                ->visible($shortages !== []),
            Grid::make(2)->schema([
                TextEntry::make('summary_channel')->label('Kênh bán')->state($draft->channel?->name),
                TextEntry::make('summary_ref')->label('Mã đơn ngoài')->state($draft->externalRef() ?? 'Tự sinh khi xác nhận'),
                TextEntry::make('summary_customer')->label('Khách')->state($draft->customer)->placeholder('Không có')->columnSpanFull(),
            ]),
            RepeatableEntry::make('summary_lines')
                ->label('Dòng xuất')
                ->state(array_map(fn (DispatchLineDraft $line): array => [
                    'product' => $line->product?->name,
                    'quantity' => DispatchResource::count($line->quantity),
                    'sale_price' => DispatchResource::money($line->salePrice),
                ], $draft->lines))
                ->table([
                    TableColumn::make('Sản phẩm'),
                    TableColumn::make('Số lượng'),
                    TableColumn::make('Giá bán'),
                ])
                ->schema([
                    TextEntry::make('product'),
                    TextEntry::make('quantity'),
                    TextEntry::make('sale_price')->placeholder('Chưa có'),
                ]),
            TextEntry::make('summary_total')
                ->label('Tổng Giá bán')
                ->state($prices === [] ? null : DispatchResource::money(array_sum($prices)))
                ->placeholder('Chưa có Giá bán'),
        ];
    }

    private function draft(): DispatchDraft
    {
        return $this->draft ??= DispatchResource::draftFromForm($this->form->getState());
    }

    /**
     * @return list<Shortage>
     */
    private function shortages(): array
    {
        return $this->shortages ??= app(ManualDispatch::class)->shortages(InventoryAction::actor(), $this->draft());
    }
}
