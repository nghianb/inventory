<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockTransition;
use App\Models\Dispatch;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Các Slot mà một Phiếu xuất Đang giữ.
 *
 * Bản ghi ở đây chỉ sống trong lúc Slot ở trạng thái Đã giữ: giao hoặc nhả đều xoá nó đi, còn lịch
 * sử thì Sổ biến động kho đã ghi đủ. Người gọi chạy trong transaction, đã khoá Phiếu xuất, và tự ghi
 * Sổ biến động kho bằng các {@see StockTransition} trả về — cùng cách {@see SlotPicker} làm.
 */
final class SlotHolds
{
    /**
     * Giữ các Slot đã khoá cho một Dòng xuất: Còn hàng → Đã giữ.
     *
     * @param  Collection<int, stdClass>  $slots  các hàng `id`, `stock_unit_id` từ {@see SlotPicker::lockAndPick()}
     * @return list<StockTransition> để người gọi ghi Sổ biến động kho
     */
    public static function hold(int $dispatchLineId, Collection $slots, CarbonInterface $now): array
    {
        DB::table('slots')
            ->whereIn('id', $slots->pluck('id'))
            ->update(['status' => SlotStatus::Reserved->value, 'updated_at' => $now]);

        DB::table('slot_holds')->insert($slots->map(fn (object $slot): array => [
            'dispatch_line_id' => $dispatchLineId,
            'slot_id' => $slot->id,
            'stock_unit_id' => $slot->stock_unit_id,
            'held_at' => $now,
        ])->all());

        return $slots->map(fn (object $slot): StockTransition => new StockTransition(
            (int) $slot->stock_unit_id,
            (int) $slot->id,
            SlotStatus::InStock,
            SlotStatus::Reserved,
        ))->values()->all();
    }

    /**
     * Slot đang giữ của một phiếu, đã khoá, gom theo Dòng xuất giữ chúng. Hình dạng mỗi hàng khớp
     * với thứ {@see SlotPicker::deliver()} nhận, để giao hàng đã giữ đi đúng đường giao thường.
     *
     * @return array<int, Collection<int, stdClass>> theo `dispatch_line_id`
     */
    public static function lockByLine(Dispatch $dispatch): array
    {
        return self::of($dispatch)
            ->select('slot_holds.slot_id AS id', 'slot_holds.stock_unit_id', 'slot_holds.dispatch_line_id')
            ->orderBy('slot_holds.slot_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('dispatch_line_id')
            ->map(fn (Collection $slots): Collection => $slots->values())
            ->all();
    }

    /**
     * Nhả mọi Slot đang giữ của một phiếu: Đã giữ → Còn hàng.
     *
     * @return list<StockTransition> rỗng khi phiếu không giữ Slot nào
     */
    public static function release(Dispatch $dispatch, CarbonInterface $now): array
    {
        $held = self::of($dispatch)
            ->select('slot_holds.slot_id', 'slot_holds.stock_unit_id')
            ->orderBy('slot_holds.slot_id')
            ->lockForUpdate()
            ->get();

        if ($held->isEmpty()) {
            return [];
        }

        $slotIds = $held->map(fn (object $hold): int => (int) $hold->slot_id)->all();

        DB::table('slots')->whereIn('id', $slotIds)->update(['status' => SlotStatus::InStock->value, 'updated_at' => $now]);
        self::forget($slotIds);

        return $held->map(fn (object $hold): StockTransition => new StockTransition(
            (int) $hold->stock_unit_id,
            (int) $hold->slot_id,
            SlotStatus::Reserved,
            SlotStatus::InStock,
        ))->values()->all();
    }

    /**
     * Bỏ bản ghi giữ của các Slot vừa được giao: Slot đã sang Đã giao nên không còn ai giữ nó.
     *
     * @param  list<int>  $slotIds
     */
    public static function forget(array $slotIds): void
    {
        DB::table('slot_holds')->whereIn('slot_id', $slotIds)->delete();
    }

    /**
     * Đơn vị hàng có Slot nào đang bị giữ không. Huỷ hàng và Đánh dấu Lỗi hỏi câu này trước khi đụng
     * tới Đơn vị hàng, để không bỏ quên Slot mà một khách đang chờ thanh toán.
     */
    public static function anyHeldForUnit(int $stockUnitId): bool
    {
        return DB::table('slot_holds')->where('stock_unit_id', $stockUnitId)->exists();
    }

    /**
     * Thông báo khi một thao tác phải lùi vì Đơn vị hàng đang có Slot bị giữ. Một chỗ duy nhất, để
     * Huỷ hàng và Đánh dấu Lỗi không nói hai kiểu khác nhau về cùng một chuyện.
     *
     * @param  string  $action  việc đang định làm, để câu cuối đọc xuôi: "… rồi Huỷ hàng."
     */
    public static function heldUnitProblem(string $action): string
    {
        return "Đơn vị hàng đang có Slot Đã giữ cho một Phiếu xuất; chờ hết hạn giữ hoặc để website huỷ đơn rồi {$action}.";
    }

    /**
     * Slot phiếu đang giữ, kèm Sản phẩm, để panel nói rõ phiếu đang giam hàng nào. Chỉ đọc, không khoá.
     *
     * @return list<array{product: string, unit: int, slot: int}>
     */
    public static function summary(Dispatch $dispatch): array
    {
        return self::of($dispatch)
            ->join('products', 'products.id', '=', 'dispatch_lines.product_id')
            ->orderBy('slot_holds.slot_id')
            ->get(['products.name AS product', 'slot_holds.stock_unit_id AS unit', 'slot_holds.slot_id AS slot'])
            ->map(fn (object $row): array => [
                'product' => (string) $row->product,
                'unit' => (int) $row->unit,
                'slot' => (int) $row->slot,
            ])
            ->all();
    }

    private static function of(Dispatch $dispatch): Builder
    {
        return DB::table('slot_holds')
            ->join('dispatch_lines', 'dispatch_lines.id', '=', 'slot_holds.dispatch_line_id')
            ->where('dispatch_lines.dispatch_id', $dispatch->getKey());
    }
}
