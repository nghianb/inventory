<?php

namespace App\Inventory\Stock;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Claims\SupplierClaims;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Đơn vị hàng chuyển Lỗi và Khôi phục. Đánh dấu Lỗi: Quản trị chuyển Lỗi không cần Báo lỗi (nhà
 * cung cấp thu hồi, hỏng trong kho). Khôi phục: Quản trị đưa Đơn vị hàng Lỗi về Hoạt động khi nhà
 * cung cấp sửa được; Báo lỗi và Đổi hàng đã làm giữ nguyên. Lúc chuyển Lỗi, Slot Còn hàng được ghi
 * mốc Tổn thất hàng Lỗi (`slots.defective_loss_at`); Khôi phục bỏ mốc đó.
 */
class StockDefect
{
    public function __construct(
        private RoleGate $roles,
        private StockLedger $ledger,
        private SupplierClaims $claims,
    ) {}

    /**
     * Đơn vị hàng Hoạt động → Lỗi, kèm lý do bắt buộc. Trả Lần giao bị ảnh hưởng để liên hệ khách;
     * không tự Báo lỗi hay Đổi hàng.
     *
     * @return list<AffectedDelivery>
     *
     * @throws MissingRole
     * @throws InvalidDefectMarking
     */
    public function markDefective(User $actor, StockUnit $unit, string $reason): array
    {
        $this->roles->authorize($actor);
        $reason = self::requiredReason($reason, 'Đánh dấu Lỗi phải nhập lý do.');

        return DB::transaction(function () use ($actor, $unit, $reason): array {
            $current = self::lock($unit);

            if ($current->status !== StockUnitStatus::Active) {
                throw new InvalidDefectMarking('Chỉ Đánh dấu Lỗi được Đơn vị hàng Hoạt động.');
            }

            $this->markDefectiveWithin($actor, $current, "Đánh dấu Lỗi: {$reason}");

            return AffectedDelivery::forUnit($current->id);
        });
    }

    /**
     * Đơn vị hàng Lỗi → Hoạt động, kèm lý do bắt buộc. Slot Còn hàng bán lại được và không còn tính
     * Tổn thất hàng Lỗi.
     *
     * @throws MissingRole
     * @throws InvalidDefectMarking
     */
    public function restore(User $actor, StockUnit $unit, string $reason): void
    {
        $this->roles->authorize($actor);
        $reason = self::requiredReason($reason, 'Khôi phục phải nhập lý do.');

        DB::transaction(function () use ($actor, $unit, $reason): void {
            $current = self::lock($unit);

            if ($current->status !== StockUnitStatus::Defective) {
                throw new InvalidDefectMarking('Chỉ Khôi phục được Đơn vị hàng Lỗi.');
            }

            $current->forceFill(['status' => StockUnitStatus::Active, 'defective_at' => null])->save();
            Slot::query()->where('stock_unit_id', $current->id)->whereNotNull('defective_loss_at')->update(['defective_loss_at' => null]);

            $this->ledger->append($actor, [
                new StockTransition($current->id, null, StockUnitStatus::Defective, StockUnitStatus::Active),
            ], "Khôi phục: {$reason}");
            $this->claims->releaseRestored($current, "Khôi phục Đơn vị hàng: {$reason}");
        });
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see markDefective()}.
     */
    public function canMarkDefective(User $actor, StockUnit $unit): bool
    {
        return $this->roles->allows($actor) && $unit->status === StockUnitStatus::Active;
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see restore()}.
     */
    public function canRestore(User $actor, StockUnit $unit): bool
    {
        return $this->roles->allows($actor) && $unit->status === StockUnitStatus::Defective;
    }

    /**
     * Chuyển Đơn vị hàng Hoạt động đã khoá sang Lỗi, không kiểm tra quyền: người gọi (Đánh dấu Lỗi,
     * Xác nhận Báo lỗi cả Đơn vị hàng) đã khoá, kiểm tra Hoạt động và chạy trong transaction. Slot Còn
     * hàng lúc này là Tồn lỗi và ghi mốc Tổn thất hàng Lỗi, trừ khi đã quá Hạn sử dụng: khi đó đã là
     * Tổn thất hết hạn, mỗi Slot chỉ tính tổn thất một lần.
     */
    public function markDefectiveWithin(User $actor, StockUnit $lockedUnit, string $ledgerReason): void
    {
        $now = now();
        $lockedUnit->forceFill(['status' => StockUnitStatus::Defective, 'defective_at' => $now])->save();

        if ($lockedUnit->expires_on === null || $lockedUnit->expires_on->gte(CarbonImmutable::today())) {
            Slot::query()->where('stock_unit_id', $lockedUnit->id)->where('status', SlotStatus::InStock)->update(['defective_loss_at' => $now]);
        }

        $this->ledger->append($actor, [
            new StockTransition($lockedUnit->id, null, StockUnitStatus::Active, StockUnitStatus::Defective),
        ], $ledgerReason);
    }

    /**
     * Khoá Đơn vị hàng như Huỷ hàng: phiếu đang chọn Slot của nó (khoá chia sẻ) giao xong trước,
     * phiếu sau bỏ qua nó.
     */
    private static function lock(StockUnit $unit): StockUnit
    {
        return StockUnit::query()->lockForUpdate()->findOrFail($unit->getKey());
    }

    /**
     * @throws InvalidDefectMarking
     */
    private static function requiredReason(string $reason, string $message): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidDefectMarking($message);
        }

        return $reason;
    }
}
