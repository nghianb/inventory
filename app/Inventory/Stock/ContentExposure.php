<?php

namespace App\Inventory\Stock;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Xử lý hàng bị coi là đã lộ. Khi lộ cả khoá mã hoá lẫn dữ liệu thì **mọi** nội dung trong kho bị coi
 * là đã lộ (ADR 0001), nên phạm vi huỷ nhận cả một Sản phẩm lẫn cả kho; kho lớn thì bấm từng Sản phẩm
 * đúng lúc đang chữa sự cố là quá chậm.
 *
 * Việc còn lại là **liệt kê Lần giao bị ảnh hưởng** ({@see AffectedDelivery::forExposedAccounts()}):
 * lần giao Tài khoản còn trong Hạn bảo hành, để nhân viên chủ động liên hệ khách.
 *
 * Cả hai làm được khi kho đang Tạm dừng xuất kho: Huỷ hàng không lấy thêm hàng ra khỏi kho, và liệt
 * kê thì chỉ đọc.
 */
class ContentExposure
{
    public function __construct(
        private RoleGate $roles,
        private StockLedger $ledger,
    ) {}

    /**
     * Số lượng sẽ huỷ và bỏ lại nếu làm lúc này, cho màn xác nhận.
     *
     * @param  ?Product  $product  null là cả kho
     *
     * @throws MissingRole
     */
    public function plan(User $actor, ?Product $product): ProductVoidTally
    {
        $this->roles->authorize($actor);

        $units = self::activeUnitIds($product, lock: false);
        $voidable = $units->diff(self::heldUnitIds($units));

        return new ProductVoidTally(
            voidedUnits: $voidable->count(),
            voidedSlots: self::inStockSlots($voidable)->count(),
            keptUnits: $units->count() - $voidable->count(),
        );
    }

    /**
     * Huỷ hàng mọi Đơn vị hàng Hoạt động trong phạm vi với lý do Lộ nội dung: Đơn vị hàng sang Đã huỷ
     * cùng mọi Slot Còn hàng của nó. Slot Đã giao giữ nguyên — khách vẫn đang dùng, và đó là việc của
     * danh sách Lần giao bị ảnh hưởng. Không giải phóng Khoá chống trùng: đây là Huỷ hàng, không phải
     * Huỷ nhập.
     *
     * Đơn vị hàng đang có Slot Đã giữ cho một Phiếu xuất được bỏ lại nguyên vẹn, cùng lý do với Huỷ
     * hàng lẻ: huỷ nó thì Slot ấy nằm lại ở trạng thái Đã giữ trên một Đơn vị hàng đã huỷ. Hạn Giữ
     * hàng tính bằng phút, nên chạy lại sau đó là hết; số bỏ lại nằm trong {@see ProductVoidTally}
     * để panel nhắc.
     *
     * Slot đang có Báo lỗi Chờ xác minh không cần kiểm tra ở đây: Báo lỗi chỉ tạo được cho Slot Đã
     * giao, mà đây chỉ đụng Slot Còn hàng — đúng như Huỷ hàng cả Đơn vị hàng.
     *
     * @param  ?Product  $product  null là cả kho
     * @param  string  $note  bắt buộc: vì sao nội dung bị coi là đã lộ
     *
     * @throws MissingRole
     * @throws InvalidVoid
     */
    public function voidExposed(User $actor, ?Product $product, string $note): ProductVoidTally
    {
        $this->roles->authorize($actor);
        $note = trim($note);

        if ($note === '') {
            throw new InvalidVoid('Huỷ hàng hàng loạt phải nhập lý do coi là đã lộ.');
        }

        return DB::transaction(function () use ($actor, $product, $note): ProductVoidTally {
            // Khoá theo thứ tự id: phiếu đang chọn Slot của các Đơn vị hàng này (khoá chia sẻ) giao
            // xong trước, phiếu sau bỏ qua chúng.
            $units = self::activeUnitIds($product, lock: true);
            $voidable = $units->diff(self::heldUnitIds($units))->values();

            if ($voidable->isEmpty()) {
                throw new InvalidVoid(sprintf(
                    'Không còn Đơn vị hàng nào huỷ được: %s không có Đơn vị hàng Hoạt động, hoặc mọi Đơn vị hàng còn lại đang có Slot Đã giữ.',
                    $product === null ? 'kho' : "Sản phẩm \"{$product->name}\"",
                ));
            }

            $now = now();
            $slots = self::inStockSlots($voidable)->orderBy('id')->get(['id', 'stock_unit_id']);

            DB::table('stock_units')->whereIn('id', $voidable)->update([
                'status' => StockUnitStatus::Voided->value,
                'void_reason' => VoidReason::ContentExposed->value,
                'voided_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('slots')->whereIn('id', $slots->pluck('id'))->update([
                'status' => SlotStatus::Voided->value,
                'void_reason' => VoidReason::ContentExposed->value,
                'voided_at' => $now,
                'updated_at' => $now,
            ]);

            $this->ledger->append($actor, [
                ...$voidable->map(fn (int $id): StockTransition => new StockTransition($id, null, StockUnitStatus::Active, StockUnitStatus::Voided))->all(),
                ...$slots->map(fn (object $slot): StockTransition => new StockTransition((int) $slot->stock_unit_id, (int) $slot->id, SlotStatus::InStock, SlotStatus::Voided))->all(),
            ], StockVoid::ledgerReason(self::ledgerSubject($product), VoidReason::ContentExposed, $note));

            return new ProductVoidTally($voidable->count(), $slots->count(), $units->count() - $voidable->count());
        });
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see voidExposed()}. Không xét phạm vi: lúc
     * dựng nút thì Quản trị chưa chọn Sản phẩm, và "không còn gì để huỷ" là câu trả lời của
     * {@see plan()} ngay trên modal.
     */
    public function canVoid(User $actor): bool
    {
        return $this->roles->allows($actor);
    }

    /**
     * Chủ ngữ của dòng Sổ biến động kho, để đọc lại còn biết lần huỷ ấy quét cả kho hay một Sản phẩm.
     */
    private static function ledgerSubject(?Product $product): string
    {
        return $product === null ? 'Huỷ hàng hàng loạt cả kho' : "Huỷ hàng hàng loạt Sản phẩm \"{$product->name}\"";
    }

    /**
     * @return Collection<int, int>
     */
    private static function activeUnitIds(?Product $product, bool $lock): Collection
    {
        return self::activeUnits($product)
            ->orderBy('id')
            ->when($lock, fn (QueryBuilder $query) => $query->lockForUpdate())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    private static function activeUnits(?Product $product): QueryBuilder
    {
        return DB::table('stock_units')
            ->where('status', StockUnitStatus::Active->value)
            ->when($product !== null, fn (QueryBuilder $ofProduct) => $ofProduct->where('product_id', $product?->getKey()));
    }

    /**
     * Đơn vị hàng đang có Slot Đã giữ, trong số đã cho.
     *
     * @param  Collection<int, int>  $unitIds
     * @return Collection<int, int>
     */
    private static function heldUnitIds(Collection $unitIds): Collection
    {
        if ($unitIds->isEmpty()) {
            return new Collection;
        }

        return DB::table('slot_holds')
            ->whereIn('stock_unit_id', $unitIds)
            ->distinct()
            ->pluck('stock_unit_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  Collection<int, int>  $unitIds
     */
    private static function inStockSlots(Collection $unitIds): QueryBuilder
    {
        return DB::table('slots')
            ->whereIn('stock_unit_id', $unitIds)
            ->where('status', SlotStatus::InStock->value);
    }
}
