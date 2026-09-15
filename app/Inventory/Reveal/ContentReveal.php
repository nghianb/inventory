<?php

namespace App\Inventory\Reveal;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\EncryptedContent;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SlotStatus;
use App\Models\ContentField;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chỗ duy nhất trả nội dung đầy đủ của một Slot. Mỗi lần xem kiểm tra quyền theo Ngữ cảnh
 * xem mã, rồi ghi Nhật ký xem mã và giải mã trong cùng transaction: không ghi được nhật ký
 * thì không có nội dung, giải mã lỗi thì không có dòng nhật ký.
 */
class ContentReveal
{
    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private ContentCrypto $crypto,
        private RevealLog $log,
    ) {}

    /**
     * @param  ?string  $reason  lý do tự do; chỉ dùng khi ngữ cảnh không tự suy ra lý do
     *
     * @throws MissingRole
     * @throws InvalidReveal
     * @throws KeyFingerprintMismatch
     */
    public function reveal(RevealActor $actor, Slot $slot, RevealContext $context, ?string $reason = null): RevealedContent
    {
        $reason = match ($context->type) {
            RevealContextType::InStock => $this->inStockReason($actor, $reason),
            RevealContextType::Batch => throw new InvalidReveal('Ngữ cảnh Lô nhập chỉ dùng để tải dòng bị bỏ khi nhập.'),
        };

        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $slot, $context, $reason): RevealedContent {
            // Khoá chia sẻ: Slot không đổi trạng thái giữa lúc kiểm tra và lúc trả nội dung.
            $current = Slot::query()->sharedLock()->findOrFail($slot->getKey());

            if ($current->status !== SlotStatus::InStock) {
                throw new InvalidReveal('Slot không còn Còn hàng; nội dung chỉ xem được qua Ngữ cảnh xem mã.');
            }

            $this->log->record($actor, $context, $reason, $current);

            return new RevealedContent($this->decrypt($current->stockUnit()->with('product.contentFields')->firstOrFail()));
        });
    }

    /**
     * Nhân viên có được xem nội dung Slot này theo lý do tự do (Quản trị, Slot Còn hàng) không;
     * để panel ẩn nút xem, không thay cho kiểm tra trong {@see reveal()}.
     */
    public function canRevealInStock(User $actor, Slot $slot): bool
    {
        return $this->roles->allows($actor) && $slot->status === SlotStatus::InStock;
    }

    /**
     * @throws MissingRole
     * @throws InvalidReveal
     */
    private function inStockReason(RevealActor $actor, ?string $reason): string
    {
        if ($actor->user === null) {
            throw new InvalidReveal('Chỉ Quản trị xem được nội dung hàng Còn hàng.');
        }

        // Không truyền Vai trò nào: chỉ Quản trị qua được.
        $this->roles->authorize($actor->user);

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new InvalidReveal('Xem nội dung hàng Còn hàng phải nhập lý do.');
        }

        return $reason;
    }

    /**
     * @return array<string, string>
     */
    private function decrypt(StockUnit $unit): array
    {
        $secret = $unit->secret_ciphertext === null
            ? []
            : (array) json_decode($this->crypto->decrypt(new EncryptedContent($unit->secret_ciphertext, (int) $unit->secret_key_version)), true, flags: JSON_THROW_ON_ERROR);
        $values = [...($unit->content ?? []), ...$secret];

        return $unit->product->contentFields->mapWithKeys(fn (ContentField $field): array => [
            $field->label => (string) ($values[$field->key] ?? ''),
        ])->all();
    }
}
