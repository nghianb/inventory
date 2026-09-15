<?php

namespace App\Inventory\Warranty;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Dispatch\AffectedDelivery;
use App\Inventory\Dispatch\DeliveryTemplate;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockTransition;
use App\Inventory\Stock\StockUnitStatus;
use App\Models\DefectReport;
use App\Models\Delivery;
use App\Models\Slot;
use App\Models\StockUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Báo lỗi và xác minh: nhân viên ghi nhận Slot đã giao mà khách báo không dùng được, xác minh rồi
 * Xác nhận (chọn Phạm vi lỗi) hoặc Bác bỏ; người tạo tự xác minh được. Bán hàng và Quản trị. Trong
 * lúc Chờ xác minh, Slot Còn hàng của cùng Đơn vị hàng không thuộc Tồn bán được. Không tự Đổi hàng.
 */
class DefectReporting
{
    /** Ảnh khách gửi: disk private của app, không phải thư mục public. */
    public const SCREENSHOT_DISK = 'local';

    public const SCREENSHOT_DIRECTORY = 'defect-reports';

    public function __construct(
        private RoleGate $roles,
        private StockLedger $ledger,
    ) {}

    /**
     * Mỗi lần giao một Báo lỗi Chờ xác minh, cùng mô tả và ảnh. Bán hàng chỉ tạo trong Hạn bảo hành
     * của Sản phẩm có bảo hành; Quản trị vượt được kèm lý do. Lỗi ở bất kỳ lần giao nào thì không
     * tạo gì.
     *
     * @param  list<Delivery>  $deliveries
     * @return list<DefectReport> theo thứ tự lần giao truyền vào
     *
     * @throws MissingRole
     * @throws InvalidDefectReport
     */
    public function report(User $actor, array $deliveries, DefectReportDraft $draft): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        $description = trim($draft->description);

        if ($description === '') {
            throw new InvalidDefectReport(['Báo lỗi phải có mô tả.']);
        }

        $screenshotPath = null;

        try {
            return DB::transaction(function () use ($actor, $deliveries, $draft, $description, &$screenshotPath): array {
                $current = $this->lockDeliveries($deliveries);
                $overrideReason = $this->ensureReportable($actor, $current, $draft->overrideReason);
                // Lưu ảnh sau khi kiểm tra xong; các Báo lỗi cùng lần tạo dùng chung một file.
                $screenshotPath = $draft->screenshot?->store(self::SCREENSHOT_DIRECTORY, self::SCREENSHOT_DISK) ?: null;

                return array_map(fn (Delivery $delivery): DefectReport => $this->insert($actor, $current[$delivery->getKey()], $overrideReason, [
                    'status' => DefectReportStatus::Pending,
                    'description' => $description,
                    'screenshot_path' => $screenshotPath,
                ]), $deliveries);
            });
        } catch (Throwable $exception) {
            // Báo lỗi không tạo được thì không để lại ảnh.
            if ($screenshotPath !== null) {
                Storage::disk(self::SCREENSHOT_DISK)->delete($screenshotPath);
            }

            throw $exception;
        }
    }

    /**
     * Xoá ảnh không còn Báo lỗi nào trỏ tới, cũ hơn một giờ: file còn sót khi tiến trình dừng giữa
     * lúc lưu ảnh và lúc commit. Ảnh mới hơn có thể thuộc Báo lỗi đang tạo dở.
     *
     * @return int số ảnh đã xoá
     */
    public function purgeOrphanScreenshots(): int
    {
        $disk = Storage::disk(self::SCREENSHOT_DISK);
        $cutoff = now()->subHour()->getTimestamp();
        $orphans = collect($disk->files(self::SCREENSHOT_DIRECTORY))
            ->filter(fn (string $path): bool => $disk->lastModified($path) < $cutoff)
            ->diff(DefectReport::query()->whereNotNull('screenshot_path')->distinct()->pluck('screenshot_path'))
            ->values();

        $disk->delete($orphans->all());

        return $orphans->count();
    }

    /**
     * Nhân viên có tạo Báo lỗi cho lần giao này lúc này không. Để panel ẩn nút, không thay cho kiểm
     * tra trong {@see report()}.
     */
    public function canReport(User $actor, Delivery $delivery): bool
    {
        return $this->roles->allows($actor, Role::BanHang)
            && self::reportBlocker($delivery) === null
            && (self::warrantyProblem($delivery) === null || $this->roles->allows($actor));
    }

    /**
     * Lần giao ngoài bảo hành: Báo lỗi cần Quản trị kèm lý do. Để panel hiện ô lý do.
     */
    public static function isOutOfWarranty(Delivery $delivery): bool
    {
        return self::warrantyProblem($delivery) !== null;
    }

    /**
     * Các lần Bác bỏ trước của những Slot này, mới nhất trước, để hiện khi tạo lại Báo lỗi.
     *
     * @param  list<Delivery>  $deliveries
     * @return Collection<int, DefectReport>
     *
     * @throws MissingRole
     */
    public function rejectedReports(User $actor, array $deliveries): Collection
    {
        $this->roles->authorize($actor, Role::BanHang);

        return DefectReport::query()
            ->with(['creator', 'verifier'])
            ->whereIn('slot_id', array_map(fn (Delivery $delivery): int => $delivery->slot_id, $deliveries))
            ->where('status', DefectReportStatus::Rejected)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Xác nhận Báo lỗi Chờ xác minh với ghi chú bắt buộc. Phạm vi cả Đơn vị hàng: Đơn vị hàng Hoạt
     * động chuyển Lỗi và ghi Sổ biến động kho (Slot Còn hàng thành Tồn lỗi); chỉ Slot: Đơn vị hàng
     * giữ nguyên, Slot trong kho bán lại được.
     *
     * @throws MissingRole
     * @throws InvalidDefectReport
     */
    public function confirm(User $actor, DefectReport $report, DefectScope $scope, string $note): void
    {
        $this->roles->authorize($actor, Role::BanHang);
        $note = self::verificationNote($note);

        DB::transaction(function () use ($actor, $report, $scope, $note): void {
            $current = self::lockPending($report);

            if ($scope === DefectScope::Unit) {
                // Khoá Đơn vị hàng như Huỷ hàng: phiếu đang chọn Slot của nó giao xong rồi mới chuyển Lỗi.
                $unit = StockUnit::query()->lockForUpdate()->findOrFail($current->stock_unit_id);

                if ($unit->status !== StockUnitStatus::Active && $unit->status !== StockUnitStatus::Defective) {
                    throw new InvalidDefectReport(["Đơn vị hàng đang {$unit->status->label()}; chỉ Xác nhận được Phạm vi lỗi ".DefectScope::Slot->label().'.']);
                }

                if ($unit->status === StockUnitStatus::Active) {
                    $unit->forceFill(['status' => StockUnitStatus::Defective])->save();
                    $this->ledger->append($actor, [
                        new StockTransition($unit->id, null, StockUnitStatus::Active, StockUnitStatus::Defective),
                    ], "Báo lỗi #{$current->id} Xác nhận cả Đơn vị hàng: {$note}");
                }
            }

            self::recordVerification($current, $actor, DefectReportStatus::Confirmed, $note, $scope);
        });
    }

    /**
     * Bác bỏ Báo lỗi Chờ xác minh với ghi chú bắt buộc; Slot trong kho của Đơn vị hàng bán lại được
     * (nếu không còn Báo lỗi Chờ xác minh khác).
     *
     * @throws MissingRole
     * @throws InvalidDefectReport
     */
    public function reject(User $actor, DefectReport $report, string $note): void
    {
        $this->roles->authorize($actor, Role::BanHang);
        $note = self::verificationNote($note);

        DB::transaction(fn () => self::recordVerification(self::lockPending($report), $actor, DefectReportStatus::Rejected, $note, null));
    }

    /**
     * Để panel ẩn nút xác minh, không thay cho kiểm tra trong {@see confirm()} và {@see reject()}.
     */
    public function canVerify(User $actor, DefectReport $report): bool
    {
        return $this->roles->allows($actor, Role::BanHang) && $report->status === DefectReportStatus::Pending;
    }

    /**
     * Đặt Kết quả xử lý Không đổi cho Báo lỗi Chờ đổi, lý do bắt buộc. Khách đã được hoàn tiền ngoài
     * kho thì đánh dấu `$refunded`: Giá bán của Dòng xuất cần sửa xuống số tiền shop thực giữ, qua
     * sửa Phiếu xuất (có lịch sử). Kết quả xử lý không đổi lại được.
     *
     * @throws MissingRole
     * @throws InvalidDefectReport
     */
    public function declineReplacement(User $actor, DefectReport $report, string $reason, bool $refunded): void
    {
        $this->roles->authorize($actor, Role::BanHang);
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidDefectReport(['Không đổi phải nhập lý do.']);
        }

        DB::transaction(function () use ($actor, $report, $reason, $refunded): void {
            // Khoá Báo lỗi: Không đổi và Đổi hàng cùng lúc thì bên sau thấy Kết quả xử lý đã đặt.
            $current = DefectReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if ($current->status !== DefectReportStatus::Confirmed) {
                throw new InvalidDefectReport(['Chỉ đặt Kết quả xử lý cho Báo lỗi Xác nhận.']);
            }

            if ($current->resolution !== DefectResolution::AwaitingReplacement) {
                throw new InvalidDefectReport(["Báo lỗi đã {$current->resolution?->label()}; không đặt Kết quả xử lý được nữa."]);
            }

            $current->forceFill([
                'resolution' => DefectResolution::NotReplaced,
                'resolution_note' => $reason,
                'refunded' => $refunded,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => now(),
            ])->save();
        });
    }

    /**
     * Để panel ẩn nút Không đổi, không thay cho kiểm tra trong {@see declineReplacement()}.
     */
    public function canDeclineReplacement(User $actor, DefectReport $report): bool
    {
        return $this->roles->allows($actor, Role::BanHang) && $report->resolution === DefectResolution::AwaitingReplacement;
    }

    /**
     * Lần giao bị ảnh hưởng của Báo lỗi đã làm Đơn vị hàng chuyển Lỗi: các lần giao khác còn Đã giao
     * của Đơn vị hàng, để liên hệ khách. Rỗng khi Báo lỗi không phải Xác nhận cả Đơn vị hàng.
     *
     * @return list<AffectedDelivery>
     *
     * @throws MissingRole
     */
    public function affectedDeliveries(User $actor, DefectReport $report): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        $current = DefectReport::query()->with('stockUnit')->findOrFail($report->getKey());

        return self::madeUnitDefective($current) ? AffectedDelivery::forUnit($current->stock_unit_id, $current->delivery_id) : [];
    }

    /**
     * Lần giao bị ảnh hưởng mà nhân viên tạo Báo lỗi hàng loạt được lúc này: chưa có Báo lỗi Chờ xác
     * minh hay Xác nhận, trong Hạn bảo hành (hoặc nhân viên là Quản trị). Để panel chọn lần giao và
     * ẩn nút, không thay cho kiểm tra trong {@see confirmAffected()}.
     *
     * @return list<AffectedDelivery>
     *
     * @throws MissingRole
     */
    public function reportableAffectedDeliveries(User $actor, DefectReport $report): array
    {
        $affected = $this->affectedDeliveries($actor, $report);
        $deliveries = Delivery::query()
            ->with(['slot', 'stockUnit'])
            ->whereKey(array_map(fn (AffectedDelivery $delivery): int => $delivery->deliveryId, $affected))
            ->get()
            ->keyBy('id');

        return array_values(array_filter($affected, fn (AffectedDelivery $delivery): bool => $this->canReport($actor, $deliveries[$delivery->deliveryId])));
    }

    /**
     * Báo lỗi hàng loạt cho Lần giao bị ảnh hưởng: mỗi lần giao một Báo lỗi tự Xác nhận cả Đơn vị
     * hàng, cùng mô tả với Báo lỗi gốc. Cùng quy tắc Hạn bảo hành như {@see report()}. Lỗi ở bất kỳ
     * lần giao nào thì không tạo gì. Không tự Đổi hàng.
     *
     * @param  list<Delivery>  $deliveries
     * @return list<DefectReport> theo thứ tự lần giao truyền vào
     *
     * @throws MissingRole
     * @throws InvalidDefectReport
     */
    public function confirmAffected(User $actor, DefectReport $source, array $deliveries, ?string $overrideReason = null): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        return DB::transaction(function () use ($actor, $source, $deliveries, $overrideReason): array {
            $current = DefectReport::query()->with('stockUnit')->sharedLock()->findOrFail($source->getKey());

            if (! self::madeUnitDefective($current)) {
                throw new InvalidDefectReport(["Chỉ tạo Báo lỗi hàng loạt từ Báo lỗi Xác nhận cả Đơn vị hàng; Báo lỗi #{$current->id} chưa làm Đơn vị hàng chuyển Lỗi."]);
            }

            $locked = $this->lockDeliveries($deliveries);
            $notAffected = $locked
                ->filter(fn (Delivery $delivery): bool => $delivery->stock_unit_id !== $current->stock_unit_id || $delivery->id === $current->delivery_id)
                ->map(fn (Delivery $delivery): string => "Lần giao {$delivery->unitLabel()}: không phải Lần giao bị ảnh hưởng của Báo lỗi #{$current->id}.");

            if ($notAffected->isNotEmpty()) {
                throw new InvalidDefectReport($notAffected->values()->all());
            }

            $reason = $this->ensureReportable($actor, $locked, $overrideReason);
            $now = now();

            return array_map(fn (Delivery $delivery): DefectReport => $this->insert($actor, $locked[$delivery->getKey()], $reason, [
                'status' => DefectReportStatus::Confirmed,
                'description' => $current->description,
                'source_defect_report_id' => $current->id,
                'scope' => DefectScope::Unit,
                'resolution' => DefectResolution::AwaitingReplacement,
                'verification_note' => "Tự Xác nhận theo Báo lỗi #{$current->id}",
                'verified_by' => $actor->getKey(),
                'verified_at' => $now,
            ]), $deliveries);
        });
    }

    /**
     * Khoá Slot rồi Đơn vị hàng theo thứ tự id. Khoá Đơn vị hàng chờ phiếu đang chọn Slot của nó
     * (khoá chia sẻ) giao xong; phiếu sau thấy Báo lỗi Chờ xác minh và bỏ qua Đơn vị hàng.
     *
     * @param  list<Delivery>  $deliveries
     * @return Collection<int, Delivery> theo id
     */
    private function lockDeliveries(array $deliveries): Collection
    {
        $current = Delivery::query()
            ->with('stockUnit')
            ->whereKey(array_map(fn (Delivery $delivery): mixed => $delivery->getKey(), $deliveries))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        Slot::query()->whereKey($current->pluck('slot_id'))->orderBy('id')->lockForUpdate()->get();
        StockUnit::query()->whereKey($current->pluck('stock_unit_id')->unique())->orderBy('id')->lockForUpdate()->get();

        return $current->each(fn (Delivery $delivery) => $delivery->load('slot'));
    }

    /**
     * Kiểm tra mọi lần giao; trả lý do vượt Hạn bảo hành cần lưu (null khi không lần giao nào ngoài
     * bảo hành).
     *
     * @param  Collection<int, Delivery>  $deliveries
     *
     * @throws InvalidDefectReport
     */
    private function ensureReportable(User $actor, Collection $deliveries, ?string $overrideReason): ?string
    {
        $overrideReason = trim((string) $overrideReason);
        $outOfWarranty = false;
        $problems = [];

        foreach ($deliveries as $delivery) {
            $problem = self::reportBlocker($delivery) ?? self::warrantyProblem($delivery);

            if ($problem === null) {
                continue;
            }

            if (! $problem->overridable || ! $this->roles->allows($actor)) {
                $problems[] = "Lần giao {$delivery->unitLabel()}: {$problem->message}";
            }

            $outOfWarranty = $outOfWarranty || $problem->overridable;
        }

        if ($problems === [] && $outOfWarranty && $overrideReason === '') {
            $problems[] = 'Báo lỗi ngoài Hạn bảo hành phải nhập lý do.';
        }

        if ($problems !== []) {
            throw new InvalidDefectReport($problems);
        }

        return $outOfWarranty ? $overrideReason : null;
    }

    private static function reportBlocker(Delivery $delivery): ?ReportProblem
    {
        if ($delivery->slot->status !== SlotStatus::Delivered) {
            return new ReportProblem('Slot không còn Đã giao; không Báo lỗi được.', overridable: false);
        }

        $open = DefectReport::query()
            ->where('slot_id', $delivery->slot_id)
            ->where('status', '<>', DefectReportStatus::Rejected)
            ->first();

        return $open === null ? null : new ReportProblem("Slot đã có Báo lỗi {$open->status->label()}.", overridable: false);
    }

    /**
     * Lần giao ngoài bảo hành: Sản phẩm không có bảo hành lúc giao, hoặc đã quá Hạn bảo hành (tính
     * cả ngày hết hạn).
     */
    private static function warrantyProblem(Delivery $delivery): ?ReportProblem
    {
        if ($delivery->warranty_days === 0) {
            return new ReportProblem('Sản phẩm không có bảo hành; chỉ Quản trị tạo Báo lỗi được, kèm lý do.', overridable: true);
        }

        if (CarbonImmutable::today()->gt($delivery->warrantyEndsOn())) {
            return new ReportProblem(sprintf(
                'đã quá Hạn bảo hành %s; chỉ Quản trị tạo Báo lỗi được, kèm lý do.',
                $delivery->warrantyEndsOn()->format(DeliveryTemplate::DATE_FORMAT),
            ), overridable: true);
        }

        return null;
    }

    private static function madeUnitDefective(DefectReport $report): bool
    {
        return $report->status === DefectReportStatus::Confirmed
            && $report->scope === DefectScope::Unit
            && $report->stockUnit->status === StockUnitStatus::Defective;
    }

    /**
     * @throws InvalidDefectReport
     */
    private static function verificationNote(string $note): string
    {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidDefectReport(['Xác minh Báo lỗi phải có ghi chú.']);
        }

        return $note;
    }

    /**
     * Khoá Báo lỗi: hai người xác minh cùng lúc thì người sau thấy Báo lỗi đã xác minh.
     *
     * @throws InvalidDefectReport
     */
    private static function lockPending(DefectReport $report): DefectReport
    {
        $current = DefectReport::query()->lockForUpdate()->findOrFail($report->getKey());

        if ($current->status !== DefectReportStatus::Pending) {
            throw new InvalidDefectReport(["Báo lỗi đã {$current->status->label()}; không xác minh lại được."]);
        }

        return $current;
    }

    private static function recordVerification(DefectReport $report, User $actor, DefectReportStatus $status, string $note, ?DefectScope $scope): void
    {
        $report->forceFill([
            'status' => $status,
            'scope' => $scope,
            'resolution' => $status === DefectReportStatus::Confirmed ? DefectResolution::AwaitingReplacement : null,
            'verification_note' => $note,
            'verified_by' => $actor->getKey(),
            'verified_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(User $actor, Delivery $delivery, ?string $overrideReason, array $attributes): DefectReport
    {
        $report = new DefectReport;
        $report->forceFill([
            'delivery_id' => $delivery->id,
            'slot_id' => $delivery->slot_id,
            'stock_unit_id' => $delivery->stock_unit_id,
            'created_by' => $actor->getKey(),
            // Chỉ lần giao ngoài bảo hành mang lý do vượt.
            'warranty_override_reason' => self::warrantyProblem($delivery) === null ? null : $overrideReason,
            ...$attributes,
        ])->save();

        return $report;
    }
}
