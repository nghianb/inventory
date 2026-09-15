<?php

namespace App\Inventory\Reveal;

use App\Models\RevealLogEntry;
use App\Models\Slot;

/**
 * Ghi Nhật ký xem mã. Người gọi ghi trong cùng transaction, trước khi trả nội dung; lý do
 * không bao giờ chứa nội dung mã.
 */
class RevealLog
{
    public function record(RevealActor $actor, RevealContext $context, string $reason, ?Slot $slot = null): RevealLogEntry
    {
        $entry = new RevealLogEntry;
        $entry->forceFill([
            'user_id' => $actor->user?->getKey(),
            'api_key_id' => $actor->apiKeyId,
            'slot_id' => $slot?->getKey(),
            'stock_unit_id' => $slot?->stock_unit_id,
            'context' => $context->type,
            'context_id' => $context->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ])->save();

        return $entry;
    }
}
