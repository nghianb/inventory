<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\InvalidVoid;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockVoid;
use App\Inventory\Stock\VoidReason;
use App\Models\Delivery;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Giao thay: sửa một lần Giao hàng nhầm do nhân viên. Huỷ hàng Slot đã giao với lý do giao nhầm (tuỳ
 * chọn cả Đơn vị hàng khi nội dung đã gửi cho khách), rồi giao một Slot của Đơn vị hàng khác theo
 * Thứ tự xuất vào cùng Phiếu xuất, liên kết với lần giao bị huỷ. Cùng Sản phẩm thì dùng Dòng xuất gốc; Sản phẩm khác
 * thì thêm Dòng xuất loại Giao thay, dòng gốc giữ nguyên số lượng. Bán hàng làm được trong hạn cấu
 * hình (mặc định 24 giờ từ lúc giao), quá hạn chỉ Quản trị kèm lý do. Không tạo Báo lỗi, không đổi
 * Đơn vị hàng sang Lỗi.
 */
class CorrectiveDelivery
{
    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private StockLedger $ledger,
        private StockVoid $voids,
    ) {}

    /**
     * @throws MissingRole
     */
    public function preview(User $actor, Delivery $delivery): CorrectionPreview
    {
        $this->roles->authorize($actor, Role::BanHang);

        $current = Delivery::query()->with('stockUnit.product')->findOrFail($delivery->getKey());

        return new CorrectionPreview(
            productName: $current->stockUnit->product->name,
            unitLabel: $current->unitLabel(),
            deliveredAt: $current->delivered_at,
            deadline: self::deadline($current),
            late: self::isLate($current),
            affected: AffectedDelivery::forUnit($current->stock_unit_id, $current->id),
        );
    }

    /**
     * Nhân viên có Giao thay được lần giao này lúc này không. Để panel ẩn nút, không thay cho kiểm
     * tra trong {@see correct()}.
     */
    public function canCorrect(User $actor, Delivery $delivery): bool
    {
        return $this->roles->allows($actor, Role::BanHang)
            && $delivery->dispatchLine->dispatch->status === DispatchStatus::Completed
            && $delivery->slot->status === SlotStatus::Delivered
            && StockVoid::pendingDefectReportId($delivery->slot_id) === null
            && (! self::isLate($delivery) || $this->roles->allows($actor));
    }

    /**
     * @return Delivery lần giao mới
     *
     * @throws MissingRole
     * @throws InvalidDispatch
     * @throws InvalidVoid
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function correct(User $actor, Delivery $delivery, CorrectionDraft $draft): Delivery
    {
        $this->roles->authorize($actor, Role::BanHang);

        if ($draft->voidUnit && ! $draft->contentSent) {
            throw new InvalidDispatch([new DispatchProblem('Chỉ Huỷ hàng cả Đơn vị hàng khi nội dung đã gửi cho khách.')]);
        }

        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $delivery, $draft): Delivery {
            $current = Delivery::query()->findOrFail($delivery->getKey());
            $line = DispatchLine::query()->with('product')->findOrFail($current->dispatch_line_id);
            // Khoá phiếu rồi Slot: hai lần Giao thay cùng lần giao chạy lần lượt, lần sau thấy Slot Đã huỷ.
            $dispatch = Dispatch::query()->lockForUpdate()->findOrFail($line->dispatch_id);
            $slot = Slot::query()->lockForUpdate()->findOrFail($current->slot_id);

            $problems = $this->problems($actor, $dispatch, $slot, $current, $draft);

            if ($problems !== []) {
                throw new InvalidDispatch($problems);
            }

            $voidReason = StockVoid::ledgerReason("Giao thay theo Phiếu xuất #{$dispatch->id}", VoidReason::WrongDelivery, $draft->reason);
            $this->voids->voidSlotWithin($actor, $slot->id, VoidReason::WrongDelivery, $voidReason);

            if ($draft->voidUnit) {
                $this->voids->voidUnitWithin($actor, $current->stock_unit_id, VoidReason::WrongDelivery, $voidReason);
            }

            $product = $draft->product ?? $line->product;
            $sameProduct = $product->getKey() === $line->product_id;
            // Không chọn lại Đơn vị hàng vừa giao nhầm: các Slot của một Tài khoản chung nội dung. Sản
            // phẩm gốc đã Ngừng bán vẫn giao thay được, như Đổi hàng cho lần giao cũ.
            [$products, $picks] = SlotPicker::lockAndPick([new DispatchLineDraft($product, 1)], exceptUnitIds: [$current->stock_unit_id], allowDiscontinued: $sameProduct);
            $lineId = $sameProduct ? $line->id : $this->insertCorrectiveLine($dispatch, (int) $product->getKey());

            $transitions = SlotPicker::deliver($lineId, $products[(int) $product->getKey()], $picks[0], $actor, now(), $current->id);
            $this->ledger->append($actor, $transitions, "Giao thay theo Phiếu xuất #{$dispatch->id}, thay lần giao #{$current->id}");

            return Delivery::query()->where('slot_id', $picks[0]->sole()->id)->firstOrFail();
        }, attempts: 3);
    }

    /**
     * @return list<DispatchProblem>
     */
    private function problems(User $actor, Dispatch $dispatch, Slot $slot, Delivery $delivery, CorrectionDraft $draft): array
    {
        if ($dispatch->status !== DispatchStatus::Completed) {
            return [new DispatchProblem('Chỉ Giao thay được trên Phiếu xuất Hoàn tất.')];
        }

        if ($slot->status !== SlotStatus::Delivered) {
            return [new DispatchProblem('Lần giao này đã bị huỷ; không Giao thay được nữa.')];
        }

        if (! self::isLate($delivery)) {
            return [];
        }

        $hours = self::hours();

        if (! $this->roles->allows($actor)) {
            return [new DispatchProblem("Đã quá {$hours} giờ kể từ lúc giao; chỉ Quản trị Giao thay được.")];
        }

        return trim((string) $draft->reason) === '' ? [new DispatchProblem("Giao thay quá {$hours} giờ phải nhập lý do.")] : [];
    }

    /**
     * Dòng xuất loại Giao thay cho Sản phẩm khác: một Slot, chưa có Giá bán (sửa được ở Sửa phiếu).
     */
    private function insertCorrectiveLine(Dispatch $dispatch, int $productId): int
    {
        $line = new DispatchLine;
        $line->forceFill([
            'dispatch_id' => $dispatch->id,
            'product_id' => $productId,
            'kind' => DispatchLineKind::Corrective,
            'quantity' => 1,
            'sale_price' => null,
        ])->save();

        return $line->id;
    }

    private static function deadline(Delivery $delivery): CarbonImmutable
    {
        return $delivery->delivered_at->addHours(self::hours());
    }

    private static function isLate(Delivery $delivery): bool
    {
        return CarbonImmutable::now()->gt(self::deadline($delivery));
    }

    private static function hours(): int
    {
        return (int) config('inventory.dispatch.corrective_hours');
    }
}
