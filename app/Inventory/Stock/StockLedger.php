<?php

namespace App\Inventory\Stock;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ghi Sổ biến động kho: ai, khi nào, từ trạng thái nào sang trạng thái nào, lý do.
 * Người gọi ghi trong cùng transaction với việc chuyển trạng thái.
 */
class StockLedger
{
    private const CHUNK = 5_000;

    /**
     * @param  list<StockTransition>  $transitions
     * @param  ?ApiKey  $apiKey  tác nhân khi lần chuyển trạng thái đến từ API xuất kho, thay cho nhân viên
     */
    public function append(?User $actor, array $transitions, ?string $reason = null, ?ApiKey $apiKey = null): void
    {
        $now = now();

        foreach (array_chunk($transitions, self::CHUNK) as $chunk) {
            DB::table('stock_ledger_entries')->insert(array_map(fn (StockTransition $transition): array => [
                'stock_unit_id' => $transition->stockUnitId,
                'slot_id' => $transition->slotId,
                'from_status' => $transition->from?->value,
                'to_status' => $transition->to->value,
                'reason' => $reason,
                'actor_id' => $actor?->getKey(),
                'api_key_id' => $apiKey?->getKey(),
                'occurred_at' => $now,
            ], $chunk));
        }
    }
}
