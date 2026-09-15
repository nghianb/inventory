<?php

namespace App\Inventory\Intake;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockTransition;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Batch;
use App\Models\BatchLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Huỷ nhập: Quản trị rút lại hàng nhập nhầm theo Dòng nhập hoặc cả Lô nhập, coi như chưa từng
 * vào kho. Chỉ Đơn vị hàng Hoạt động mà mọi Slot còn Còn hàng bị Huỷ nhập; phần còn lại ở lại.
 * Đơn vị hàng bị Huỷ nhập nhả Khoá chống trùng để nhập lại được đúng mã, nhưng bản ghi được giữ
 * và mỗi lần chuyển trạng thái ghi Sổ biến động kho. Không phải Huỷ hàng, không tính tổn thất.
 */
class ImportReversal
{
    private const RESTORE_CHUNK = 1_000;

    /**
     * Đơn vị hàng Huỷ nhập được; tham số: trạng thái Hoạt động, trạng thái Còn hàng.
     */
    private const REVERSIBLE = 'stock_units.status = ? AND NOT EXISTS (SELECT 1 FROM slots WHERE slots.stock_unit_id = stock_units.id AND slots.status <> ?)';

    public function __construct(
        private RoleGate $roles,
        private StockLedger $ledger,
    ) {}

    /**
     * Số lượng sẽ Huỷ nhập và giữ lại nếu làm lúc này, cho màn xác nhận.
     *
     * @throws MissingRole
     */
    public function plan(User $actor, Batch|BatchLine $target): ReversalPlan
    {
        $this->roles->authorize($actor);

        return self::tally(self::lineIds($target));
    }

    /**
     * @param  ?string  $reason  lý do tự do, ghi kèm vào Sổ biến động kho
     * @return ReversalPlan số thực đã Huỷ nhập và giữ lại
     *
     * @throws MissingRole
     * @throws InvalidBatch
     */
    public function reverse(User $actor, Batch|BatchLine $target, ?string $reason = null): ReversalPlan
    {
        $this->roles->authorize($actor);

        // Deadlock với Lô nhập đang ghi hoặc Huỷ nhập khác đụng cùng Tài khoản cũ: chạy lại.
        return DB::transaction(function () use ($actor, $target, $reason): ReversalPlan {
            $batch = Batch::query()->lockForUpdate()->findOrFail($target instanceof Batch ? $target->getKey() : $target->batch_id);

            if ($batch->status !== BatchStatus::Confirmed) {
                throw new InvalidBatch('Chỉ Huỷ nhập được Lô nhập đã xác nhận.');
            }

            $lineIds = self::lineIds($target);
            self::lockStock($lineIds);
            $plan = self::tally($lineIds);

            if ($plan->reversedUnits === 0) {
                throw new InvalidBatch('Không còn Đơn vị hàng nào Huỷ nhập được: mọi Đơn vị hàng đã bị Huỷ nhập hoặc có Slot không còn Còn hàng.');
            }

            $in = self::placeholders($lineIds);
            $now = now();

            $units = DB::select(
                'UPDATE stock_units SET status = ?, holds_dedupe_key = false, updated_at = ?
                 WHERE batch_line_id IN ('.$in.') AND '.self::REVERSIBLE.'
                 RETURNING id, batch_line_id, renews_stock_unit_id',
                [StockUnitStatus::Reversed->value, $now, ...$lineIds, StockUnitStatus::Active->value, SlotStatus::InStock->value],
            );

            $slots = DB::select(
                'UPDATE slots SET status = ?, updated_at = ?
                 WHERE status = ? AND stock_unit_id IN (SELECT id FROM stock_units WHERE batch_line_id IN ('.$in.') AND status = ?)
                 RETURNING id, stock_unit_id',
                [SlotStatus::Reversed->value, $now, SlotStatus::InStock->value, ...$lineIds, StockUnitStatus::Reversed->value],
            );

            self::restoreRenewedKeys($units);

            foreach (collect($units)->countBy('batch_line_id') as $lineId => $count) {
                BatchLine::query()->whereKey($lineId)->increment('reversed_count', $count);
            }

            $this->ledger->append($actor, [
                ...array_map(fn (object $unit): StockTransition => new StockTransition((int) $unit->id, null, StockUnitStatus::Active, StockUnitStatus::Reversed), $units),
                ...array_map(fn (object $slot): StockTransition => new StockTransition((int) $slot->stock_unit_id, (int) $slot->id, SlotStatus::InStock, SlotStatus::Reversed), $slots),
            ], self::ledgerReason($batch, $target, $reason));

            return new ReversalPlan(count($units), count($slots), $plan->keptUnits);
        }, attempts: 3);
    }

    /**
     * Đếm theo trạng thái hiện tại. Đơn vị hàng đã Huỷ nhập không tính vào phần giữ lại.
     *
     * @param  list<int>  $lineIds
     */
    private static function tally(array $lineIds): ReversalPlan
    {
        if ($lineIds === []) {
            return new ReversalPlan(0, 0, 0);
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) FILTER (WHERE reversible) AS reversed_units,
                    COALESCE(SUM(slots) FILTER (WHERE reversible), 0) AS reversed_slots,
                    COUNT(*) FILTER (WHERE NOT reversible AND status <> ?) AS kept_units
             FROM (
                 SELECT stock_units.status,
                        (SELECT COUNT(*) FROM slots WHERE slots.stock_unit_id = stock_units.id) AS slots,
                        '.self::REVERSIBLE.' AS reversible
                 FROM stock_units
                 WHERE batch_line_id IN ('.self::placeholders($lineIds).')
             ) units',
            [StockUnitStatus::Reversed->value, StockUnitStatus::Active->value, SlotStatus::InStock->value, ...$lineIds],
        );

        return new ReversalPlan((int) $row->reversed_units, (int) $row->reversed_slots, (int) $row->kept_units);
    }

    /**
     * Khoá Đơn vị hàng rồi Slot theo thứ tự id: trạng thái không đổi giữa lúc đếm và lúc Huỷ nhập.
     *
     * @param  list<int>  $lineIds
     */
    private static function lockStock(array $lineIds): void
    {
        $units = fn ($query) => $query->select('id')->from('stock_units')->whereIn('batch_line_id', $lineIds);

        DB::table('stock_units')->whereIn('batch_line_id', $lineIds)->orderBy('id')->lockForUpdate()->pluck('id');
        DB::table('slots')->whereIn('stock_unit_id', $units)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /**
     * Tài khoản nhập lại bị Huỷ nhập thì Đơn vị hàng cũ chiếm lại Khoá chống trùng, như lần nhập
     * lại chưa từng xảy ra. Không Đơn vị hàng nào khác chiếm được khoá này trong lúc đó, vì
     * Đơn vị hàng vừa Huỷ nhập giữ nó tới giờ. Đơn vị hàng cũ đã Huỷ nhập thì không lấy lại khoá.
     *
     * @param  list<object{id: int, batch_line_id: int, renews_stock_unit_id: ?int}>  $units
     */
    private static function restoreRenewedKeys(array $units): void
    {
        $ids = array_values(array_filter(array_map(fn (object $unit): ?int => $unit->renews_stock_unit_id === null ? null : (int) $unit->renews_stock_unit_id, $units)));

        foreach (array_chunk($ids, self::RESTORE_CHUNK) as $chunk) {
            DB::table('stock_units')
                ->whereIn('id', $chunk)
                ->where('status', '<>', StockUnitStatus::Reversed->value)
                ->update(['holds_dedupe_key' => true, 'updated_at' => now()]);
        }
    }

    /**
     * @return list<int>
     */
    private static function lineIds(Batch|BatchLine $target): array
    {
        return $target instanceof BatchLine
            ? [(int) $target->getKey()]
            : BatchLine::query()->where('batch_id', $target->getKey())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private static function ledgerReason(Batch $batch, Batch|BatchLine $target, ?string $reason): string
    {
        $subject = $target instanceof BatchLine
            ? "Huỷ nhập Dòng nhập #{$target->getKey()} của Lô nhập #{$batch->id}"
            : "Huỷ nhập Lô nhập #{$batch->id}";

        return $reason === null || trim($reason) === '' ? $subject : "{$subject}: ".trim($reason);
    }

    /**
     * @param  list<int>  $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
