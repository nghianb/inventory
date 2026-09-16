<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SellableStock;
use App\Models\Dispatch;
use App\Models\SalesChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Xuất kho thủ công: nhân viên tạo Phiếu xuất cho một đơn, hoặc Giao thêm vào phiếu đã Hoàn tất;
 * hệ thống chọn Slot theo Thứ tự xuất ({@see SlotPicker}) và giao ngay trong một transaction. Cả
 * phần giao đủ hoặc thất bại. Slot được chọn bằng `FOR UPDATE SKIP LOCKED`, nên hai tiến trình xuất
 * cùng lúc không bao giờ chọn trùng Slot. Phần ghi nằm ở {@see DispatchWriter}, dùng chung với
 * {@see ApiDispatch}.
 */
class ManualDispatch
{
    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private SellableStock $stock,
        private DispatchWriter $writer,
    ) {}

    /**
     * Lỗi kiểm tra của phiếu, không tính tồn kho. Rỗng thì tạo được (nếu đủ hàng).
     *
     * @return list<DispatchProblem>
     *
     * @throws MissingRole
     */
    public function check(User $actor, DispatchDraft $draft): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        return self::problems($draft);
    }

    /**
     * Lỗi kiểm tra của phần Giao thêm vào một Phiếu xuất, không tính tồn kho.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @return list<DispatchProblem>
     *
     * @throws MissingRole
     */
    public function checkAdditional(User $actor, Dispatch $dispatch, array $lines): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        return self::additionalProblems(Dispatch::query()->findOrFail($dispatch->getKey()), $lines);
    }

    /**
     * Dòng xuất thiếu hàng theo Tồn bán được lúc này, cho modal xác nhận. Không khoá gì, nên
     * {@see create()} và {@see addLines()} vẫn có thể báo thiếu hàng khi phiếu khác vừa lấy mất.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @return list<Shortage>
     *
     * @throws MissingRole
     */
    public function shortages(User $actor, array $lines): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        $lines = array_values(array_filter($lines, fn (DispatchLineDraft $line): bool => $line->product !== null && $line->quantity > 0));
        $available = $this->stock->counts(array_values(array_unique(array_map(fn (DispatchLineDraft $line): int => (int) $line->product?->getKey(), $lines))));
        $shortages = [];

        foreach ($lines as $line) {
            $product = $line->product;
            assert($product !== null);

            if ($line->quantity > $available[(int) $product->getKey()]) {
                $shortages[] = new Shortage((int) $product->getKey(), $product->code, $product->name, $line->quantity, $available[(int) $product->getKey()]);
            }
        }

        return $shortages;
    }

    /**
     * Tạo Phiếu xuất và giao ngay: mỗi Slot chuyển Còn hàng → Đã giao, ghi Giao hàng (giữ thời
     * hạn bảo hành của Sản phẩm lúc giao) và Sổ biến động kho. Phiếu tạo ra đã Hoàn tất.
     *
     * @throws MissingRole
     * @throws InvalidDispatch
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function create(User $actor, DispatchDraft $draft): Dispatch
    {
        $this->roles->authorize($actor, Role::BanHang);

        $problems = self::problems($draft);

        if ($problems !== []) {
            throw new InvalidDispatch($problems);
        }

        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $draft): Dispatch {
            $channel = $draft->channel;
            assert($channel !== null);

            [$products, $picks] = SlotPicker::lockAndPick($draft->lines);
            $dispatchActor = DispatchActor::staff($actor);
            $dispatch = $this->writer->insert($dispatchActor, $channel, $draft->externalRef(), $draft->customer, $draft->note);

            $this->writer->deliver($dispatchActor, $dispatch, $draft->lines, $products, $picks, DispatchLineKind::Sale, "Giao hàng theo Phiếu xuất #{$dispatch->id}");

            return $dispatch;
        }, attempts: 3);
    }

    /**
     * Giao thêm: thêm Dòng xuất loại Giao thêm vào một Phiếu xuất Hoàn tất và giao ngay, cùng Thứ
     * tự xuất và kiểm tra tồn với {@see create()}. Phần thêm giao đủ hoặc thất bại; thất bại thì
     * phiếu giữ nguyên. Thành công thì màn kết quả của phiếu chuyển sang Slot vừa giao, cho người
     * vừa Giao thêm.
     *
     * @param  list<DispatchLineDraft>  $lines
     *
     * @throws MissingRole
     * @throws InvalidDispatch
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function addLines(User $actor, Dispatch $dispatch, array $lines): Dispatch
    {
        $this->roles->authorize($actor, Role::BanHang);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($actor, $dispatch, $lines): Dispatch {
            // Khoá phiếu: hai lần Giao thêm cùng phiếu chạy lần lượt, giới hạn Slot tính đúng.
            $current = Dispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());
            $problems = self::additionalProblems($current, $lines);

            if ($problems !== []) {
                throw new InvalidDispatch($problems);
            }

            [$products, $picks] = SlotPicker::lockAndPick($lines);
            $lineIds = $this->writer->deliver(DispatchActor::staff($actor), $current, $lines, $products, $picks, DispatchLineKind::Additional, "Giao thêm theo Phiếu xuất #{$current->id}");

            $current->forceFill([
                'result_by' => $actor->getKey(),
                'result_from_line_id' => $lineIds[0],
                'result_revealed_at' => null,
            ])->save();

            return $current;
        }, attempts: 3);
    }

    /**
     * Nhân viên có Giao thêm được vào phiếu này lúc này không: Bán hàng, phiếu Hoàn tất. Để panel ẩn
     * nút, không thay cho kiểm tra trong {@see addLines()}.
     */
    public function canAddLines(User $actor, Dispatch $dispatch): bool
    {
        return $dispatch->status === DispatchStatus::Completed && $this->roles->allows($actor, Role::BanHang);
    }

    /**
     * @return list<DispatchProblem>
     */
    private static function problems(DispatchDraft $draft): array
    {
        $problems = [];
        $channel = $draft->channel === null ? null : SalesChannel::query()->find($draft->channel->getKey());
        $ref = $draft->externalRef();

        if ($channel === null) {
            $problems[] = new DispatchProblem('Chưa chọn Kênh bán.');
        } elseif ($channel->isHidden()) {
            $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" đã ngừng dùng.");
        } elseif ($channel->isApi()) {
            // Đơn của kênh API vào kho qua API, nơi mã đơn ngoài là khoá idempotency của website.
            $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" là kênh API; đơn của kênh này chỉ vào kho qua API.");
        }

        if ($channel !== null && $ref === null && $channel->requires_external_ref) {
            $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" bắt buộc mã đơn ngoài.");
        }

        if ($ref !== null && ($problem = ExternalRefs::problem($channel, $ref)) !== null) {
            $problems[] = $problem;
        }

        if ($draft->lines === []) {
            $problems[] = new DispatchProblem('Phiếu xuất phải có ít nhất một Dòng xuất.');
        }

        return [...$problems, ...DispatchWriter::lineProblems($draft->lines, 0)];
    }

    /**
     * Phần Giao thêm: phiếu phải Hoàn tất; Dòng xuất theo cùng quy tắc với tạo phiếu, giới hạn Slot
     * tính cả Slot phiếu đã giao. Sản phẩm trùng Dòng xuất cũ được, vì mỗi lần mua thêm là một dòng.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @return list<DispatchProblem>
     */
    private static function additionalProblems(Dispatch $dispatch, array $lines): array
    {
        $problems = [];

        if ($dispatch->status !== DispatchStatus::Completed) {
            $problems[] = new DispatchProblem('Chỉ Giao thêm được vào Phiếu xuất Hoàn tất.');
        }

        if ($lines === []) {
            $problems[] = new DispatchProblem('Giao thêm phải có ít nhất một Dòng xuất.');
        }

        return [...$problems, ...DispatchWriter::lineProblems($lines, $dispatch->deliveries()->count())];
    }
}
