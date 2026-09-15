<?php

namespace App\Filament\Resources\Dispatches\Pages;

use App\Filament\Resources\Dispatches\DispatchResource;
use App\Filament\Support\InventoryAction;
use App\Inventory\Dispatch\DeliveryLookup;
use App\Models\Delivery;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Danh sách Phiếu xuất: tìm theo mã đơn ngoài, khách và các bộ lọc của bảng; tìm theo Khoá chống
 * trùng qua modal để chuỗi dán vào không nằm trên URL.
 */
class ListDispatches extends ListRecords
{
    protected static string $resource = DispatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lookupDedupeKey')
                ->label('Tìm theo Khoá chống trùng')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->modalHeading('Tìm theo Khoá chống trùng')
                ->modalDescription('Dán mã hoặc tên đăng nhập khách gửi tới. Chuỗi được chuẩn hoá theo từng Sản phẩm và so khớp chính xác; kết quả không hiện nội dung và không ghi Nhật ký xem mã.')
                ->modalSubmitActionLabel('Tìm')
                ->schema([
                    Textarea::make('value')
                        ->label('Khoá chống trùng')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (Action $action, DeliveryLookup $lookup, array $data): void {
                    $deliveries = InventoryAction::attempt($action, fn () => $lookup->byDedupeKey(InventoryAction::actor(), (string) $data['value']));

                    $this->replaceMountedAction('dedupeKeyResults', [
                        'rows' => $deliveries->map(function (Delivery $delivery): array {
                            $dispatch = $delivery->dispatchLine->dispatch;

                            return [
                                'dispatch' => sprintf(
                                    '<a href="%s" style="text-decoration: underline">#%d · %s · %s</a>',
                                    e(DispatchResource::getUrl('view', ['record' => $dispatch])),
                                    $dispatch->id,
                                    e($dispatch->salesChannel->name),
                                    e($dispatch->external_ref),
                                ),
                                'product' => $delivery->dispatchLine->product->name,
                                'unit' => $delivery->unitLabel(),
                                'delivered_at' => $delivery->delivered_at->format('d/m/Y H:i'),
                                'warranty' => $delivery->warrantyEndsOn()->format('d/m/Y'),
                                'status' => $delivery->slot->status->label(),
                            ];
                        })->values()->all(),
                    ]);
                }),
            CreateAction::make()->label('Tạo Phiếu xuất'),
        ];
    }

    public function dedupeKeyResultsAction(): Action
    {
        return Action::make('dedupeKeyResults')
            ->modalHeading(fn (array $arguments): string => sprintf('Tìm thấy %s lần giao', DispatchResource::count(count($arguments['rows'] ?? []))))
            ->modalWidth(Width::FiveExtraLarge)
            ->schema(fn (array $arguments): array => [
                Text::make('Không có lần giao nào khớp. Mã đã giao luôn tìm được; hãy kiểm tra lại chuỗi khách gửi.')
                    ->visible(($arguments['rows'] ?? []) === []),
                RepeatableEntry::make('rows')
                    ->hiddenLabel()
                    ->state($arguments['rows'] ?? [])
                    ->table([
                        TableColumn::make('Phiếu xuất'),
                        TableColumn::make('Sản phẩm'),
                        TableColumn::make('Đơn vị hàng'),
                        TableColumn::make('Giao lúc'),
                        TableColumn::make('Hạn bảo hành'),
                        TableColumn::make('Trạng thái'),
                    ])
                    ->schema([
                        TextEntry::make('dispatch')->html(),
                        TextEntry::make('product'),
                        TextEntry::make('unit'),
                        TextEntry::make('delivered_at'),
                        TextEntry::make('warranty'),
                        TextEntry::make('status')->badge(),
                    ])
                    ->visible(($arguments['rows'] ?? []) !== []),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }
}
