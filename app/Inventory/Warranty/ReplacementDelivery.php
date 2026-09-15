<?php

namespace App\Inventory\Warranty;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\OutOfStock;
use App\Inventory\Dispatch\SlotPicker;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\StockLedger;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\Replacement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Đổi hàng: giao một Slot khác thay cho Slot có Báo lỗi Xác nhận đang Chờ đổi, vào một Dòng xuất
 * loại Đổi hàng (không có Giá bán) của Phiếu xuất gốc. Lần giao mới kế thừa Hạn bảo hành của lần
 * giao gốc; Đổi hàng nối tiếp luôn trỏ về lần giao gốc. Chọn Slot theo Thứ tự xuất nhưng thay Hạn
 * còn lại tối thiểu bằng Hạn sử dụng phủ Hạn bảo hành kế thừa; không có Slot phủ đủ thì chỉ đổi khi
 * nhân viên chấp nhận Slot hạn ngắn hơn. Mặc định cùng Sản phẩm (kể cả Ngừng bán); Sản phẩm khác
 * bắt buộc lý do. Bán hàng tự làm lần đổi 1–2 của chuỗi; từ lần 3 Bán hàng yêu cầu, Quản trị duyệt
 * rồi Bán hàng mới Đổi hàng được (Quản trị tự làm thì không cần duyệt riêng). Giá vốn Slot thay thế
 * lưu làm Chi phí đổi hàng, gắn Sản phẩm và Nhà cung cấp của Đơn vị hàng lỗi.
 */
class ReplacementDelivery
{
    /** Từ lần đổi thứ này trong một chuỗi, Đổi hàng cần Quản trị duyệt. */
    public const APPROVAL_SEQUENCE = 3;

    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private StockLedger $ledger,
    ) {}

    /**
     * @param  ?Product  $product  Sản phẩm muốn giao ra; null là cùng Sản phẩm với Đơn vị hàng lỗi
     *
     * @throws MissingRole
     */
    public function preview(User $actor, DefectReport $report, ?Product $product = null): ReplacementPreview
    {
        $this->roles->authorize($actor, Role::BanHang);

        $delivery = Delivery::query()->with('stockUnit.product')->findOrFail($report->delivery_id);
        $original = self::originalDelivery($delivery);
        $sequence = self::nextSequence($original);
        $candidate = SlotPicker::replacementCandidates($product ?? $delivery->stockUnit->product, CarbonImmutable::today(), $original->warrantyEndsOn(), [$delivery->stock_unit_id])->first();

        return new ReplacementPreview(
            productName: $delivery->stockUnit->product->name,
            unitLabel: $delivery->unitLabel(),
            warrantyEndsOn: $original->warrantyEndsOn(),
            sequence: $sequence,
            requiresApproval: $sequence >= self::APPROVAL_SEQUENCE,
            availability: match (true) {
                $candidate === null => ReplacementAvailability::OutOfStock,
                (bool) $candidate->covers => ReplacementAvailability::Covering,
                default => ReplacementAvailability::ShorterOnly,
            },
            candidateExpiresOn: $candidate?->expires_on === null ? null : CarbonImmutable::parse($candidate->expires_on),
        );
    }

    /**
     * Sản phẩm chọn được để giao ra: Sản phẩm đang bán, cùng Sản phẩm của Đơn vị hàng lỗi kể cả khi
     * đã Ngừng bán. Để panel liệt kê, không thay cho kiểm tra trong {@see replace()}.
     *
     * @return Collection<int, Product>
     */
    public function selectableProducts(DefectReport $report): Collection
    {
        return Product::query()
            ->where(fn (Builder $query) => $query->onSale()->orWhere('id', $report->stockUnit->product_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Nhân viên có Đổi hàng cho Báo lỗi này lúc này không. Để panel khoá nút, không thay cho kiểm tra
     * trong {@see replace()}.
     */
    public function canReplace(User $actor, DefectReport $report): bool
    {
        return $this->roles->allows($actor, Role::BanHang)
            && $report->isAwaitingReplacement()
            && ! $this->needsApproval($actor, $report, self::nextSequence(self::originalDelivery($report->delivery)));
    }

    /**
     * Bán hàng yêu cầu Quản trị duyệt Đổi hàng từ lần đổi thứ 3 của chuỗi; Báo lỗi vào danh sách Chờ
     * Quản trị duyệt.
     *
     * @throws MissingRole
     * @throws InvalidReplacement
     */
    public function requestApproval(User $actor, DefectReport $report): void
    {
        $this->roles->authorize($actor, Role::BanHang);

        DB::transaction(function () use ($actor, $report): void {
            $current = self::lockAwaiting($report);
            self::ensureApprovable($current);

            if ($current->replacement_approval_requested_at !== null) {
                throw new InvalidReplacement(['Đã yêu cầu Quản trị duyệt Đổi hàng.']);
            }

            $current->forceFill([
                'replacement_approval_requested_by' => $actor->getKey(),
                'replacement_approval_requested_at' => now(),
            ])->save();
        });
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see requestApproval()}. Quản trị không cần yêu cầu.
     */
    public function canRequestApproval(User $actor, DefectReport $report): bool
    {
        return $this->roles->allows($actor, Role::BanHang)
            && ! $this->roles->allows($actor)
            && $report->replacement_approval_requested_at === null
            && $this->isApprovable($report);
    }

    /**
     * Quản trị duyệt Đổi hàng từ lần đổi thứ 3 của chuỗi, để Bán hàng Đổi hàng được; không cần có yêu
     * cầu trước.
     *
     * @throws MissingRole
     * @throws InvalidReplacement
     */
    public function approve(User $actor, DefectReport $report): void
    {
        // Không truyền Vai trò nào: chỉ Quản trị duyệt.
        $this->roles->authorize($actor);

        DB::transaction(function () use ($actor, $report): void {
            $current = self::lockAwaiting($report);
            self::ensureApprovable($current);

            $current->forceFill([
                'replacement_approved_by' => $actor->getKey(),
                'replacement_approved_at' => now(),
            ])->save();
        });
    }

    /**
     * Để panel ẩn nút, không thay cho kiểm tra trong {@see approve()}.
     */
    public function canApprove(User $actor, DefectReport $report): bool
    {
        return $this->roles->allows($actor) && $this->isApprovable($report);
    }

    /**
     * @throws MissingRole
     * @throws InvalidReplacement
     * @throws InvalidDispatch
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function replace(User $actor, DefectReport $report, ReplacementDraft $draft): Replacement
    {
        $this->roles->authorize($actor, Role::BanHang);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $report, $draft): Replacement {
            $current = self::lockAwaiting($report);
            $delivery = Delivery::query()->with(['stockUnit.product', 'stockUnit.batchLine.batch', 'dispatchLine'])->findOrFail($current->delivery_id);
            // Khoá lần giao gốc: các lần đổi của cùng chuỗi chạy lần lượt để đếm đúng lần đổi thứ mấy.
            $original = Delivery::query()->with('stockUnit')->lockForUpdate()->findOrFail(self::originalDelivery($delivery)->id);
            $sequence = self::nextSequence($original);
            $unit = $delivery->stockUnit;
            $product = $draft->product ?? $unit->product;
            $sameProduct = (int) $product->getKey() === $unit->product_id;
            $productChangeReason = trim((string) $draft->productChangeReason);

            $problems = [];

            if ($this->needsApproval($actor, $current, $sequence)) {
                $problems[] = "Lần đổi thứ {$sequence} trong chuỗi cần Quản trị duyệt.";
            }

            if (! $sameProduct && $productChangeReason === '') {
                $problems[] = 'Đổi hàng sang Sản phẩm khác phải nhập lý do.';
            }

            if ($problems !== []) {
                throw new InvalidReplacement($problems);
            }

            $warrantyEndsOn = $original->warrantyEndsOn();
            // Không chọn lại Đơn vị hàng lỗi, kể cả Phạm vi chỉ Slot: các Slot của một Tài khoản chung nội dung.
            $slot = SlotPicker::lockAndPickReplacement($product, $warrantyEndsOn, [$unit->id], allowDiscontinued: $sameProduct);

            if (! $slot->covers && ! $draft->acceptShorterExpiry) {
                throw new InvalidReplacement([sprintf(
                    'Không có Slot nào có Hạn sử dụng phủ Hạn bảo hành %s; Slot hạn dài nhất hết hạn %s. Chấp nhận Slot hạn ngắn hơn để Đổi hàng.',
                    $warrantyEndsOn->format(DeliveryTemplate::DATE_FORMAT),
                    CarbonImmutable::parse($slot->expires_on)->format(DeliveryTemplate::DATE_FORMAT),
                )]);
            }

            $now = now();
            $lineId = self::insertReplacementLine($delivery->dispatchLine->dispatch_id, (int) $product->getKey());
            $transitions = SlotPicker::deliver($lineId, $product, collect([$slot]), $actor, $now, inheritsWarrantyFrom: $original);
            $this->ledger->append($actor, $transitions, "Đổi hàng theo Báo lỗi #{$current->id}, thay lần giao #{$delivery->id}");

            $replacement = new Replacement;
            $replacement->forceFill([
                'defect_report_id' => $current->id,
                'original_delivery_id' => $original->id,
                'sequence' => $sequence,
                'delivery_id' => Delivery::query()->where('slot_id', $slot->id)->value('id'),
                'product_change_reason' => $sameProduct ? null : $productChangeReason,
                'short_expiry_accepted' => ! $slot->covers,
                'cost' => (int) $slot->cost,
                'defective_product_id' => $unit->product_id,
                'supplier_id' => $unit->batchLine->batch->supplier_id,
                'created_by' => $actor->getKey(),
                // Quản trị tự làm thì chính họ là người duyệt.
                'approved_by' => $sequence >= self::APPROVAL_SEQUENCE ? ($current->replacement_approved_by ?? $actor->getKey()) : null,
            ])->save();

            $current->forceFill([
                'resolution' => DefectResolution::Replaced,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => $now,
            ])->save();

            return $replacement;
        }, attempts: 3);
    }

    private function needsApproval(User $actor, DefectReport $report, int $sequence): bool
    {
        return $sequence >= self::APPROVAL_SEQUENCE
            && $report->replacement_approved_at === null
            && ! $this->roles->allows($actor);
    }

    /**
     * Báo lỗi Chờ đổi, từ lần đổi thứ 3 của chuỗi, chưa được duyệt.
     */
    private function isApprovable(DefectReport $report): bool
    {
        return $report->isAwaitingReplacement()
            && $report->replacement_approved_at === null
            && self::nextSequence(self::originalDelivery($report->delivery)) >= self::APPROVAL_SEQUENCE;
    }

    /**
     * @throws InvalidReplacement
     */
    private static function ensureApprovable(DefectReport $report): void
    {
        if (self::nextSequence(self::originalDelivery($report->delivery)) < self::APPROVAL_SEQUENCE) {
            throw new InvalidReplacement(['Chỉ từ lần đổi thứ '.self::APPROVAL_SEQUENCE.' trong chuỗi mới cần Quản trị duyệt.']);
        }

        if ($report->replacement_approved_at !== null) {
            throw new InvalidReplacement(['Đổi hàng đã được Quản trị duyệt.']);
        }
    }

    /**
     * Khoá Báo lỗi: Đổi hàng, Không đổi, yêu cầu và duyệt cùng Báo lỗi chạy lần lượt.
     *
     * @throws InvalidReplacement
     */
    private static function lockAwaiting(DefectReport $report): DefectReport
    {
        $current = DefectReport::query()->lockForUpdate()->findOrFail($report->getKey());

        if ($current->status !== DefectReportStatus::Confirmed) {
            throw new InvalidReplacement(['Chỉ Đổi hàng cho Báo lỗi Xác nhận.']);
        }

        if (! $current->isAwaitingReplacement()) {
            throw new InvalidReplacement(["Báo lỗi đã có Kết quả xử lý {$current->resolution?->label()}; không Đổi hàng được nữa."]);
        }

        return $current;
    }

    /**
     * Lần giao gốc của chuỗi: lần giao này, hoặc lần giao gốc của Đổi hàng đã giao ra nó.
     */
    private static function originalDelivery(Delivery $delivery): Delivery
    {
        $originalId = Replacement::query()->where('delivery_id', $delivery->id)->value('original_delivery_id');

        return $originalId === null ? $delivery : Delivery::query()->with('stockUnit')->findOrFail($originalId);
    }

    private static function nextSequence(Delivery $original): int
    {
        return Replacement::query()->where('original_delivery_id', $original->id)->count() + 1;
    }

    /**
     * Dòng xuất loại Đổi hàng: một Slot, không có Giá bán, để Chi phí đổi hàng không vào Lãi gộp.
     */
    private static function insertReplacementLine(int $dispatchId, int $productId): int
    {
        $line = new DispatchLine;
        $line->forceFill([
            'dispatch_id' => $dispatchId,
            'product_id' => $productId,
            'kind' => DispatchLineKind::Replacement,
            'quantity' => 1,
            'sale_price' => null,
        ])->save();

        return $line->id;
    }
}
