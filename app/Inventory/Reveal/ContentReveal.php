<?php

namespace App\Inventory\Reveal;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\DeliveredContent;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Dispatch\DispatchResultContent;
use App\Inventory\Dispatch\DispatchResultExport;
use App\Inventory\Dispatch\DispatchResultFormat;
use App\Inventory\Encryption\ContentCrypto;
use App\Inventory\Encryption\EncryptedContent;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SlotStatus;
use App\Models\ContentField;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            RevealContextType::Delivery => throw new InvalidReveal('Nội dung lần Giao hàng chỉ xem qua màn kết quả xuất kho hoặc Xem mã của lần giao.'),
        };

        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $slot, $context, $reason): RevealedContent {
            // Khoá chia sẻ: Slot không đổi trạng thái giữa lúc kiểm tra và lúc trả nội dung.
            $current = Slot::query()->sharedLock()->findOrFail($slot->getKey());

            if ($current->status !== SlotStatus::InStock) {
                throw new InvalidReveal('Slot không còn Còn hàng; nội dung chỉ xem được qua Ngữ cảnh xem mã.');
            }

            $this->log->record($actor, $context, $reason, $current);

            $unit = $current->stockUnit()->with('product.contentFields')->firstOrFail();

            return new RevealedContent(self::byLabel($unit, $this->decryptedValues($unit)));
        });
    }

    /**
     * Màn kết quả ngay sau khi xuất kho: nội dung các Slot vừa giao của Phiếu xuất (cả phiếu khi
     * vừa tạo, chỉ phần thêm sau Giao thêm), đã ghép Mẫu giao hàng của Sản phẩm. Người vừa xuất kho
     * không cần quyền xem mã riêng, nhưng chỉ được một lần: mỗi Slot ghi một dòng Nhật ký xem mã
     * ngữ cảnh Giao hàng trong cùng transaction với việc đánh dấu màn kết quả đã hiện. Rời màn này
     * thì xem lại là một lần xem mã riêng.
     *
     * Từ ngưỡng che trở lên chỉ trả dạng che, không giải mã và không ghi nhật ký; nội dung
     * đầy đủ lấy qua {@see copyAllDispatchResult()} hoặc {@see exportDispatchResult()}.
     *
     * @throws MissingRole
     * @throws InvalidReveal
     * @throws KeyFingerprintMismatch
     */
    public function revealDispatchResult(User $actor, Dispatch $dispatch): DispatchResultContent
    {
        $this->roles->authorize($actor, Role::BanHang);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $dispatch): DispatchResultContent {
            $current = Dispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());

            self::ensureResultActor($actor, $current);

            $deliveries = self::resultDeliveries($current);
            $masked = $deliveries->count() >= (int) config('inventory.dispatch.result_mask_slots');

            // Dạng che không có plaintext: tải lại trang trong thời hạn tải vẫn hiện lại được để
            // Copy tất cả và Tải file; dạng đầy đủ chỉ hiện một lần.
            if ($current->result_revealed_at !== null && ! ($masked && self::isResultDownloadOpen($current))) {
                throw new InvalidReveal('Màn kết quả chỉ hiện một lần ngay sau khi xuất kho.');
            }

            $current->forceFill(['result_revealed_at' => $current->result_revealed_at ?? now()])->save();

            // Phiếu lớn: không bày plaintext ra màn hình, nên không giải mã và không ghi nhật ký.
            if ($masked) {
                return new DispatchResultContent(true, $deliveries->map(fn (Delivery $delivery): DeliveredContent => new DeliveredContent(
                    deliveryId: $delivery->id,
                    productName: $delivery->stockUnit->product->name,
                    stockUnitId: $delivery->stock_unit_id,
                    slotId: $delivery->slot_id,
                    fields: $delivery->stockUnit->maskedContent(),
                    message: null,
                    expiresOn: $delivery->stockUnit->expires_on,
                    warrantyEndsOn: $delivery->warrantyEndsOn(),
                ))->values()->all());
            }

            return new DispatchResultContent(false, $this->revealDeliveries($actor, $current, $deliveries, "Màn kết quả Phiếu xuất #{$current->id}"));
        });
    }

    /**
     * Tải TXT (theo Mẫu giao hàng) hoặc CSV từ màn kết quả. Chỉ người vừa xuất kho, trong thời hạn
     * tải ngay sau khi màn kết quả hiện; mỗi lần tải ghi Nhật ký xem mã cho mọi Slot.
     *
     * @throws MissingRole
     * @throws InvalidReveal
     * @throws KeyFingerprintMismatch
     */
    public function exportDispatchResult(User $actor, Dispatch $dispatch, DispatchResultFormat $format): DispatchResultExport
    {
        return $this->revealAfterResult(
            $actor,
            $dispatch,
            "Tải {$format->label()} Phiếu xuất #{$dispatch->getKey()}",
            fn (array $slots): DispatchResultExport => DispatchResultExport::of((int) $dispatch->getKey(), $format, $slots),
        );
    }

    /**
     * Copy tất cả từ màn kết quả dạng che: tin nhắn theo Mẫu giao hàng của mọi Slot, có dòng phân
     * cách. Cùng điều kiện với tải file; ghi Nhật ký xem mã cho mọi Slot.
     *
     * @throws MissingRole
     * @throws InvalidReveal
     * @throws KeyFingerprintMismatch
     */
    public function copyAllDispatchResult(User $actor, Dispatch $dispatch): string
    {
        return $this->revealAfterResult(
            $actor,
            $dispatch,
            "Copy tất cả Phiếu xuất #{$dispatch->getKey()}",
            fn (array $slots): string => DeliveredContent::copyAll($slots),
        );
    }

    /**
     * Người vừa xuất kho lấy lại nội dung đầy đủ trong thời hạn tải ngay sau khi màn kết quả hiện.
     *
     * @template T
     *
     * @param  callable(list<DeliveredContent>): T  $build
     * @return T
     *
     * @throws MissingRole
     * @throws InvalidReveal
     * @throws KeyFingerprintMismatch
     */
    private function revealAfterResult(User $actor, Dispatch $dispatch, string $reason, callable $build): mixed
    {
        $this->roles->authorize($actor, Role::BanHang);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $dispatch, $reason, $build): mixed {
            $current = Dispatch::query()->sharedLock()->findOrFail($dispatch->getKey());

            self::ensureResultActor($actor, $current);

            if (! self::isResultDownloadOpen($current)) {
                $minutes = (int) config('inventory.dispatch.result_download_minutes');

                throw new InvalidReveal("Chỉ lấy được nội dung từ màn kết quả, trong {$minutes} phút sau khi màn kết quả hiện.");
            }

            return $build($this->revealDeliveries($actor, $current, self::resultDeliveries($current), $reason));
        });
    }

    /**
     * Xem lại mã của một lần Giao hàng để gửi lại cho khách, đã ghép Mẫu giao hàng. Bán hàng xem
     * được mọi Phiếu xuất trong Hạn bảo hành; quá hạn chỉ Quản trị. Mỗi lần ghi một dòng Nhật ký
     * xem mã ngữ cảnh Giao hàng.
     *
     * @throws MissingRole
     * @throws InvalidReveal
     * @throws KeyFingerprintMismatch
     */
    public function revealDelivery(User $actor, Delivery $delivery): DeliveredContent
    {
        $this->roles->authorize($actor, Role::BanHang);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $delivery): DeliveredContent {
            $deliveries = Delivery::query()
                ->with(['slot', 'stockUnit.product.contentFields', 'dispatchLine.dispatch'])
                ->whereKey($delivery->getKey())
                ->get();
            $current = $deliveries->firstOrFail();

            if (! $this->canRevealDelivery($actor, $current)) {
                throw new InvalidReveal(sprintf(
                    'Lần giao đã quá Hạn bảo hành %s; chỉ Quản trị xem được mã.',
                    $current->warrantyEndsOn()->format(DeliveryTemplate::DATE_FORMAT),
                ));
            }

            $dispatch = $current->dispatchLine->dispatch;

            return $this->revealDeliveries($actor, $dispatch, $deliveries, "Xem mã Phiếu xuất #{$dispatch->id}")[0];
        });
    }

    /**
     * Nhân viên có được Xem mã lần giao này lúc này không: Quản trị luôn được, Bán hàng trong Hạn
     * bảo hành (tính cả ngày hết hạn). Để panel ẩn nút xem, không thay cho kiểm tra trong
     * {@see revealDelivery()}.
     */
    public function canRevealDelivery(User $actor, Delivery $delivery): bool
    {
        return $this->roles->allows($actor)
            || ($this->roles->allows($actor, Role::BanHang) && ! CarbonImmutable::today()->gt($delivery->warrantyEndsOn()));
    }

    /**
     * Màn kết quả đã hiện và chưa quá thời hạn tải.
     */
    private static function isResultDownloadOpen(Dispatch $dispatch): bool
    {
        return $dispatch->result_revealed_at !== null
            && ! $dispatch->result_revealed_at->addMinutes((int) config('inventory.dispatch.result_download_minutes'))->isPast();
    }

    /**
     * @throws InvalidReveal
     */
    private static function ensureResultActor(User $actor, Dispatch $dispatch): void
    {
        if ($dispatch->result_by !== (int) $actor->getKey()) {
            throw new InvalidReveal('Chỉ người vừa xuất kho xem được màn kết quả.');
        }
    }

    /**
     * Lần Giao hàng của lần xuất kho gần nhất: cả phiếu khi vừa tạo, hoặc chỉ các Dòng xuất của lần
     * Giao thêm gần nhất.
     *
     * @return EloquentCollection<int, Delivery>
     */
    private static function resultDeliveries(Dispatch $dispatch): EloquentCollection
    {
        return $dispatch->deliveries()
            ->when($dispatch->result_from_line_id !== null, fn (Builder $query) => $query->where('deliveries.dispatch_line_id', '>=', $dispatch->result_from_line_id))
            ->orderBy('deliveries.id')
            ->with(['slot', 'stockUnit.product.contentFields'])
            ->get();
    }

    /**
     * Nội dung đầy đủ đã ghép Mẫu giao hàng; mỗi Slot ghi một dòng Nhật ký xem mã ngữ cảnh Giao
     * hàng trước khi giải mã. Người gọi chạy trong transaction.
     *
     * @param  EloquentCollection<int, Delivery>  $deliveries
     * @return list<DeliveredContent>
     */
    private function revealDeliveries(User $actor, Dispatch $dispatch, EloquentCollection $deliveries, string $reason): array
    {
        $staff = RevealActor::staff($actor);

        return $deliveries
            ->map(function (Delivery $delivery) use ($dispatch, $staff, $reason): DeliveredContent {
                $this->log->record($staff, RevealContext::delivery($delivery), $reason, $delivery->slot);
                $unit = $delivery->stockUnit;
                $values = $this->decryptedValues($unit);
                $fields = self::byLabel($unit, $values);

                return new DeliveredContent(
                    deliveryId: $delivery->id,
                    productName: $unit->product->name,
                    stockUnitId: $delivery->stock_unit_id,
                    slotId: $delivery->slot_id,
                    fields: $fields,
                    message: DeliveryTemplate::render(
                        $unit->product->delivery_template,
                        $values,
                        $fields,
                        $unit->product->name,
                        $dispatch->external_ref,
                        $unit->expires_on,
                        $delivery->warrantyEndsOn(),
                    ),
                    expiresOn: $unit->expires_on,
                    warrantyEndsOn: $delivery->warrantyEndsOn(),
                );
            })
            ->values()
            ->all();
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
     * Nội dung đầy đủ đã giải mã, theo định danh Trường nội dung.
     *
     * @return array<string, string>
     */
    private function decryptedValues(StockUnit $unit): array
    {
        $secret = $unit->secret_ciphertext === null
            ? []
            : (array) json_decode($this->crypto->decrypt(new EncryptedContent($unit->secret_ciphertext, (int) $unit->secret_key_version)), true, flags: JSON_THROW_ON_ERROR);
        $values = [...($unit->content ?? []), ...$secret];

        return $unit->product->contentFields->mapWithKeys(fn (ContentField $field): array => [
            $field->key => (string) ($values[$field->key] ?? ''),
        ])->all();
    }

    /**
     * @param  array<string, string>  $values  theo định danh Trường nội dung
     * @return array<string, string> theo tên hiển thị, đúng thứ tự trường
     */
    private static function byLabel(StockUnit $unit, array $values): array
    {
        return $unit->product->contentFields->mapWithKeys(fn (ContentField $field): array => [
            $field->label => $values[$field->key],
        ])->all();
    }
}
