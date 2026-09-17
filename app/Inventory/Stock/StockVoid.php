<?php

namespace App\Inventory\Stock;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\SlotHolds;
use App\Inventory\Warranty\DefectReportStatus;
use App\Models\DefectReport;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Huỷ hàng: loại Slot hoặc Đơn vị hàng khỏi vòng đời bán vì lý do không phải lỗi hàng. Không giải
 * phóng Khoá chống trùng (Tài khoản nhả khoá khi được nhập lại). Mỗi lần chuyển trạng thái ghi Sổ
 * biến động kho. Ngoài luồng Giao thay chỉ Quản trị làm được.
 */
class StockVoid
{
    public function __construct(
        private RoleGate $roles,
        private StockLedger $ledger,
    ) {}

    /**
     * Slot Còn hàng hoặc Đã giao → Đã huỷ.
     *
     * @param  ?string  $note  ghi chú tự do, ghi kèm vào Sổ biến động kho
     *
     * @throws MissingRole
     * @throws InvalidVoid
     */
    public function voidSlot(User $actor, Slot $slot, VoidReason $reason, ?string $note = null): void
    {
        $this->roles->authorize($actor);

        DB::transaction(fn () => $this->voidSlotWithin($actor, (int) $slot->getKey(), $reason, self::ledgerReason('Huỷ hàng', $reason, $note)));
    }

    /**
     * Đơn vị hàng Hoạt động → Đã huỷ, cùng mọi Slot Còn hàng của nó. Slot Đã giao giữ nguyên: khách
     * vẫn đang dùng.
     *
     * @throws MissingRole
     * @throws InvalidVoid
     */
    public function voidUnit(User $actor, StockUnit $unit, VoidReason $reason, ?string $note = null): void
    {
        $this->roles->authorize($actor);

        DB::transaction(fn () => $this->voidUnitWithin($actor, (int) $unit->getKey(), $reason, self::ledgerReason('Huỷ hàng', $reason, $note)));
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see voidSlot()}.
     */
    public function canVoidSlot(User $actor, Slot $slot): bool
    {
        return $this->roles->allows($actor) && self::isVoidable($slot) && ! self::isDefectiveStock($slot) && self::pendingDefectReportId($slot->id) === null;
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see voidUnit()}.
     */
    public function canVoidUnit(User $actor, StockUnit $unit): bool
    {
        return $this->roles->allows($actor)
            && $unit->status === StockUnitStatus::Active
            && ! SlotHolds::anyHeldForUnit((int) $unit->getKey());
    }

    /**
     * Huỷ hàng Slot, không kiểm tra quyền: người gọi (Giao thay) đã kiểm tra và chạy
     * trong transaction.
     *
     * @throws InvalidVoid
     */
    public function voidSlotWithin(User $actor, int $slotId, VoidReason $reason, string $ledgerReason): void
    {
        $slot = Slot::query()->lockForUpdate()->findOrFail($slotId);

        // Nói rõ vì sao, thay vì để nhân viên đoán: Slot đang được giữ cho một khách chờ thanh toán.
        if ($slot->status === SlotStatus::Reserved) {
            throw new InvalidVoid('Slot đang được giữ cho một Phiếu xuất; chờ hết hạn giữ hoặc để website huỷ đơn rồi Huỷ hàng.');
        }

        if (! self::isVoidable($slot)) {
            throw new InvalidVoid('Chỉ Huỷ hàng được Slot Còn hàng hoặc Đã giao.');
        }

        // Tồn lỗi đã tính Tổn thất hàng Lỗi; huỷ thêm thì một Slot tính tổn thất hai lần.
        if (self::isDefectiveStock($slot)) {
            throw new InvalidVoid('Slot Còn hàng của Đơn vị hàng Lỗi là Tồn lỗi; Khôi phục Đơn vị hàng trước khi Huỷ hàng.');
        }

        // Báo lỗi phải được xác minh trước: huỷ Slot khi còn chờ thì Báo lỗi treo, Đơn vị hàng ngừng bán mãi.
        if (($reportId = self::pendingDefectReportId($slot->id)) !== null) {
            throw new InvalidVoid("Slot đang có Báo lỗi Chờ xác minh #{$reportId}; hãy xác minh trước.");
        }

        $from = $slot->status;
        $slot->forceFill(['status' => SlotStatus::Voided, 'void_reason' => $reason, 'voided_at' => now()])->save();

        $this->ledger->append($actor, [new StockTransition($slot->stock_unit_id, $slot->id, $from, SlotStatus::Voided)], $ledgerReason);
    }

    /**
     * Huỷ hàng Đơn vị hàng, không kiểm tra quyền: người gọi (Giao thay) đã kiểm tra
     * và chạy trong transaction. Khoá Đơn vị hàng trước: phiếu đang chọn Slot của nó (khoá chia sẻ)
     * giao xong rồi mới huỷ, phiếu sau bỏ qua nó.
     *
     * @throws InvalidVoid
     */
    public function voidUnitWithin(User $actor, int $unitId, VoidReason $reason, string $ledgerReason): void
    {
        $unit = StockUnit::query()->lockForUpdate()->findOrFail($unitId);

        if ($unit->status !== StockUnitStatus::Active) {
            throw new InvalidVoid('Chỉ Huỷ hàng được Đơn vị hàng Hoạt động.');
        }

        // Huỷ cả Đơn vị hàng chỉ đụng Slot Còn hàng, nên Slot đang giữ sẽ ở lại trạng thái Đã giữ
        // trên một Đơn vị hàng đã huỷ, rồi hết hạn giữ lại quay về Còn hàng thành hàng chết.
        if (SlotHolds::anyHeldForUnit((int) $unit->getKey())) {
            throw new InvalidVoid(SlotHolds::heldUnitProblem('Huỷ hàng'));
        }

        $now = now();
        $unit->forceFill(['status' => StockUnitStatus::Voided, 'void_reason' => $reason, 'voided_at' => $now])->save();

        $slotIds = collect(DB::select(
            'UPDATE slots SET status = ?, void_reason = ?, voided_at = ?, updated_at = ? WHERE stock_unit_id = ? AND status = ? RETURNING id',
            [SlotStatus::Voided->value, $reason->value, $now, $now, $unit->id, SlotStatus::InStock->value],
        ))->map(fn (object $slot): int => (int) $slot->id)->sort()->values();

        $this->ledger->append($actor, [
            new StockTransition($unit->id, null, StockUnitStatus::Active, StockUnitStatus::Voided),
            ...$slotIds->map(fn (int $slotId): StockTransition => new StockTransition($unit->id, $slotId, SlotStatus::InStock, SlotStatus::Voided))->all(),
        ], $ledgerReason);
    }

    /**
     * Lý do ghi Sổ biến động kho: "Huỷ hàng: Lộ nội dung: ghi chú".
     */
    public static function ledgerReason(string $subject, VoidReason $reason, ?string $note): string
    {
        $note = trim((string) $note);

        return "{$subject}: {$reason->label()}".($note === '' ? '' : ": {$note}");
    }

    /**
     * Báo lỗi Chờ xác minh của Slot, nếu có. Huỷ hàng (kể cả trong Giao thay) chờ xác minh xong.
     */
    public static function pendingDefectReportId(int $slotId): ?int
    {
        $id = DefectReport::query()->where('slot_id', $slotId)->where('status', DefectReportStatus::Pending)->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function isVoidable(Slot $slot): bool
    {
        return $slot->status === SlotStatus::InStock || $slot->status === SlotStatus::Delivered;
    }

    private static function isDefectiveStock(Slot $slot): bool
    {
        return $slot->status === SlotStatus::InStock && $slot->stockUnit->status === StockUnitStatus::Defective;
    }
}
