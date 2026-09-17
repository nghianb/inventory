<?php

namespace App\Filament\Resources\Dispatches\Widgets;

use App\Filament\Resources\RevealLogEntries\RevealLogEntryResource;
use App\Filament\Support\InventoryAction;
use App\Models\Dispatch;
use App\Models\RevealLogEntry;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Nội dung các Slot của một Phiếu xuất đã được xem bởi ai, khi nào. Chỉ Quản trị thấy.
 *
 * Liệt kê **mọi** lượt xem của các Slot đã giao, bất kể Ngữ cảnh xem mã: Giao hàng, Báo lỗi,
 * Đổi hàng, Khiếu nại nhà cung cấp, hay Quản trị xem lúc hàng còn trong kho. Mục đích là điều
 * tra rò rỉ, mà ai xem qua đường nào thì cũng đã thấy mã của khách; cột Ngữ cảnh cho biết lượt
 * xem đi qua đâu. Ngữ cảnh Lô nhập không lọt vào đây được: dòng bị bỏ khi nhập không thành Slot
 * nên dòng nhật ký của nó không gắn Slot nào.
 *
 * Màn chỉ đọc: xem danh sách này không phải là xem mã, nên không ghi thêm dòng Nhật ký xem mã.
 */
class DispatchRevealLogEntries extends TableWidget
{
    #[Locked]
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return InventoryAction::actor()->can('viewAny', RevealLogEntry::class);
    }

    public function mount(): void
    {
        abort_unless(self::canView() && InventoryAction::actor()->can('view', $this->dispatchRecord()), 403);
    }

    public function table(Table $table): Table
    {
        return RevealLogEntryResource::configureColumns($table)
            ->heading('Đã được xem bởi')
            ->description('Mọi lượt xem nội dung các Slot của phiếu này, kể cả qua Báo lỗi, Đổi hàng hay lúc hàng còn trong kho.')
            ->query(fn (): Builder => RevealLogEntry::query()
                ->whereIn('slot_id', $this->dispatchRecord()->deliveries()->select('deliveries.slot_id')->getQuery()))
            ->emptyStateHeading('Chưa ai xem nội dung của phiếu này.');
    }

    private function dispatchRecord(): Dispatch
    {
        $record = $this->record;
        assert($record instanceof Dispatch);

        return $record;
    }
}
