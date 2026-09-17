<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\DedupeLookup;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\SalesChannel;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Ghi nhận giao bù: Quản trị ghi lại một lần Giao hàng đã thực sự xảy ra nhưng bị mất khỏi kho do
 * khôi phục từ backup. Slot được chọn **đích danh** qua Khoá chống trùng khách đang giữ chứ không
 * theo Thứ tự xuất — ngoại lệ duy nhất, vì phải đúng cái mã khách đã nhận, không phải một cái bất kỳ.
 *
 * Vì thế nó không đi qua {@see SlotPicker::lockAndPick()} và làm được khi kho đang Tạm dừng xuất kho:
 * đó chính là lúc cần nó nhất, và nó không lấy thêm hàng ra khỏi kho mà chỉ chép lại thứ đã rời kho.
 */
class RecordedLostDelivery
{
    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private DedupeLookup $dedupe,
        private StockLedger $ledger,
        private DispatchWriter $writer,
    ) {}

    /**
     * Các Slot Còn hàng khớp Khoá chống trùng, để Quản trị chọn đúng cái đã giao. Chỉ dạng che, nên
     * bước chọn này không phải một lần xem mã và không ghi Nhật ký xem mã.
     *
     * @return list<RecordedSlot>
     *
     * @throws MissingRole
     * @throws KeyFingerprintMismatch
     */
    public function candidates(User $actor, #[SensitiveParameter] string $dedupeKey): array
    {
        $this->roles->authorize($actor);
        // Sai khoá HMAC thì mọi hash lệch và tra cứu âm thầm không thấy gì: báo lỗi thay vì trả rỗng.
        $this->fingerprints->verify();

        $units = $this->dedupe->matchingUnits($dedupeKey);

        if ($units === null) {
            return [];
        }

        return $units
            ->with(['product.contentFields', 'slots'])
            ->where('stock_units.status', StockUnitStatus::Active)
            ->orderBy('stock_units.id')
            ->get()
            ->flatMap(fn (StockUnit $unit): array => $unit->slots
                ->where('status', SlotStatus::InStock)
                ->map(fn (Slot $slot): RecordedSlot => new RecordedSlot(
                    slotId: $slot->id,
                    stockUnitId: $unit->id,
                    productName: $unit->product->name,
                    maskedContent: collect($unit->maskedContent())
                        ->map(fn (string $value, string $label): string => "{$label}: {$value}")
                        ->implode(' · '),
                    expiresOn: $unit->expires_on,
                ))
                ->all())
            ->values()
            ->all();
    }

    /**
     * Ghi lại lần giao: Slot Còn hàng → Đã giao trong một Dòng xuất loại Ghi nhận giao bù, kèm lý do
     * vào Sổ biến động kho. Phiếu xuất là phiếu Hoàn tất đã có, hoặc một phiếu mới dựng từ Kênh bán
     * và mã đơn ngoài của đơn cũ.
     *
     * @return Delivery lần giao vừa ghi
     *
     * @throws MissingRole
     * @throws InvalidDispatch
     * @throws KeyFingerprintMismatch
     */
    public function record(User $actor, RecordedDeliveryDraft $draft): Delivery
    {
        $this->roles->authorize($actor);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $draft): Delivery {
            $chosen = $draft->slot;
            $reason = trim((string) $draft->reason);
            $deliveredAt = $draft->deliveredAt ?? CarbonImmutable::now();
            $problems = [];

            if ($chosen === null) {
                $problems[] = new DispatchProblem('Chưa chọn Slot; hãy tra Khoá chống trùng khách đang giữ.');
            }

            if ($reason === '') {
                $problems[] = new DispatchProblem('Ghi nhận giao bù phải nhập lý do.');
            }

            // Mốc giao là mốc tính Hạn bảo hành, nên ghi lùi về đúng ngày khách nhận hàng được; ghi
            // tới tương lai thì thành kéo dài bảo hành cho một lần giao chưa xảy ra.
            if ($deliveredAt->isFuture()) {
                $problems[] = new DispatchProblem('Thời điểm giao không được ở tương lai.');
            }

            if ($draft->salePrice !== null && $draft->salePrice < 0) {
                $problems[] = new DispatchProblem('Giá bán không được âm.');
            }

            if ($problems !== []) {
                throw new InvalidDispatch($problems);
            }

            // Khoá Slot rồi Đơn vị hàng: hai lần ghi nhận cùng một Slot chạy lần lượt, lần sau thấy
            // Slot đã Đã giao; phiếu đang chọn Slot ấy giao xong trước rồi mới tới lượt.
            $slot = Slot::query()->lockForUpdate()->findOrFail($chosen->getKey());
            $unit = StockUnit::query()->with('product')->lockForUpdate()->findOrFail($slot->stock_unit_id);

            if ($slot->status !== SlotStatus::InStock) {
                throw new InvalidDispatch([new DispatchProblem("Slot #{$slot->id} đang {$slot->status->label()}; chỉ ghi nhận được Slot Còn hàng.")]);
            }

            if ($unit->status !== StockUnitStatus::Active) {
                throw new InvalidDispatch([new DispatchProblem("Đơn vị hàng #{$unit->id} đang {$unit->status->label()}; chỉ ghi nhận được Slot của Đơn vị hàng Hoạt động.")]);
            }

            $dispatch = $draft->dispatch === null ? $this->open($actor, $draft) : self::lockCompleted($draft->dispatch);
            $lineId = self::insertRecordedLine($dispatch, (int) $unit->product_id, $draft->salePrice);

            $picked = SlotPicker::pickedRow($slot->id, $slot->stock_unit_id);
            $transitions = SlotPicker::deliver($lineId, $unit->product, collect([$picked]), $actor, $deliveredAt);

            $this->ledger->append($actor, $transitions, "Ghi nhận giao bù vào Phiếu xuất #{$dispatch->id}: {$reason}");

            return Delivery::query()->where('slot_id', $slot->id)->firstOrFail();
        }, attempts: 3);
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see record()}.
     */
    public function canRecord(User $actor): bool
    {
        return $this->roles->allows($actor);
    }

    /**
     * Phiếu xuất mới cho lần giao đã mất, Hoàn tất ngay vì hàng đã ra khỏi kho từ trước. Kênh bán
     * loại API cũng chọn được ở đây, khác form xuất kho thủ công: đơn của website cũng mất khi khôi
     * phục, và đường API không có cách nào ghi lại một lần giao đã xảy ra.
     *
     * @throws InvalidDispatch
     */
    private function open(User $actor, RecordedDeliveryDraft $draft): Dispatch
    {
        $channel = $draft->channel === null ? null : SalesChannel::query()->find($draft->channel->getKey());
        $ref = $draft->externalRef();
        $problems = [];

        if ($channel === null) {
            $problems[] = new DispatchProblem('Chưa chọn Phiếu xuất cũ, cũng chưa chọn Kênh bán để tạo phiếu mới.');
        } elseif ($channel->isHidden()) {
            $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" đã ngừng dùng.");
        }

        if ($channel !== null && $ref === null && $channel->requires_external_ref) {
            $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" bắt buộc mã đơn ngoài.");
        }

        if ($ref !== null && ($problem = ExternalRefs::problem($channel, $ref)) !== null) {
            $problems[] = $problem;
        }

        if ($problems !== []) {
            throw new InvalidDispatch($problems);
        }

        assert($channel !== null);

        return $this->writer->insert(DispatchActor::staff($actor), $channel, $ref, $draft->customer, 'Phiếu dựng lại bằng Ghi nhận giao bù.');
    }

    /**
     * Phiếu xuất cũ, đã khoá. Chỉ phiếu Hoàn tất: phiếu Đang giữ còn đang chờ website xác nhận, phiếu
     * Hết hạn giữ vẫn giao lại được theo đường thường, còn Đã huỷ là trạng thái cuối.
     *
     * @throws InvalidDispatch
     */
    private static function lockCompleted(Dispatch $dispatch): Dispatch
    {
        $current = Dispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());

        if ($current->status !== DispatchStatus::Completed) {
            throw new InvalidDispatch([new DispatchProblem(
                "Phiếu xuất #{$current->id} đang {$current->status->label()}; chỉ Ghi nhận giao bù vào phiếu Hoàn tất."
            )]);
        }

        return $current;
    }

    /**
     * Dòng xuất loại Ghi nhận giao bù: một Slot, có Giá bán nếu Quản trị biết đơn cũ thu bao nhiêu.
     */
    private static function insertRecordedLine(Dispatch $dispatch, int $productId, ?int $salePrice): int
    {
        $line = new DispatchLine;
        $line->forceFill([
            'dispatch_id' => $dispatch->id,
            'product_id' => $productId,
            'kind' => DispatchLineKind::Recorded,
            'quantity' => 1,
            'sale_price' => $salePrice,
        ])->save();

        return $line->id;
    }
}
