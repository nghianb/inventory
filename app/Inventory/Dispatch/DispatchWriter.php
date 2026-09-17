<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\SalesChannel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Phần ghi chung của các đường xuất kho (panel và API): chiếm mã đơn ngoài, chèn Phiếu xuất, chèn
 * Dòng xuất, giữ hoặc giao các Slot đã khoá, và ghi Sổ biến động kho. Người gọi chạy trong
 * transaction, đã kiểm tra nghiệp vụ và đã khoá Slot bằng {@see SlotPicker::lockAndPick()}.
 *
 * Đây cũng là chỗ duy nhất đổi trạng thái Phiếu xuất theo vòng đời Giữ hàng: Đang giữ → Hoàn tất
 * ({@see deliverHeld()}), Đang giữ → Đã huỷ hoặc Hết hạn giữ ({@see release()}), Hết hạn giữ →
 * Hoàn tất ({@see deliverAgain()}).
 */
final class DispatchWriter
{
    private const GENERATED_REF_ATTEMPTS = 50;

    public function __construct(private StockLedger $ledger) {}

    /**
     * Chiếm mã đơn ngoài (ON CONFLICT, không làm hỏng transaction) rồi mới chèn phiếu với id lấy trước:
     * mã nhập tay đã bị chiếm thì báo trùng, mã tự sinh đã bị chiếm (nhân viên từng gõ hoặc sửa phiếu
     * sang đúng mã đó) thì lấy số kế.
     *
     * @param  ?string  $ref  null thì tự sinh mã theo ngày
     * @param  DispatchStatus  $status  Hoàn tất khi phiếu giữ và giao một bước; Đang giữ khi website xin Giữ hàng
     * @param  ?CarbonInterface  $holdExpiresAt  hạn Giữ hàng, chỉ có với phiếu Đang giữ
     *
     * @throws InvalidDispatch
     */
    public function insert(
        DispatchActor $actor,
        SalesChannel $channel,
        ?string $ref,
        ?string $customer,
        ?string $note,
        DispatchStatus $status = DispatchStatus::Completed,
        ?CarbonInterface $holdExpiresAt = null,
    ): Dispatch {
        // Hai tham số này là một cặp: chỉ phiếu Đang giữ mới có hạn Giữ hàng, và luôn phải có.
        assert(($status === DispatchStatus::Holding) === ($holdExpiresAt !== null));

        for ($attempt = 0; $attempt < self::GENERATED_REF_ATTEMPTS; $attempt++) {
            $candidate = $ref ?? self::nextGeneratedRef();
            $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('dispatches', 'id')) AS id")->id;

            if (! ExternalRefs::claim($channel->id, $candidate, $id)) {
                if ($ref !== null) {
                    throw new InvalidDispatch([ExternalRefs::taken($channel, $ref)]);
                }

                continue;
            }

            $now = now();

            DB::table('dispatches')->insert([
                'id' => $id,
                'sales_channel_id' => $channel->id,
                'external_ref' => $candidate,
                'customer' => self::blankToNull($customer),
                'note' => self::blankToNull($note),
                'status' => $status->value,
                'created_by' => $actor->user?->getKey(),
                'created_by_api_key_id' => $actor->apiKey?->getKey(),
                // Màn kết quả xuất kho là màn của nhân viên: phiếu do Khoá API tạo không có ai xem.
                'result_by' => $actor->user?->getKey(),
                'completed_at' => $status === DispatchStatus::Completed ? $now : null,
                'hold_expires_at' => $holdExpiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Dispatch::query()->findOrFail($id);
        }

        throw new InvalidDispatch([new DispatchProblem('Không sinh được mã đơn ngoài; hãy nhập mã đơn.')]);
    }

    /**
     * Chèn Dòng xuất và giao ngay các Slot đã khoá, ghi Sổ biến động kho với tác nhân của lần xuất.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @param  EloquentCollection<int, Product>  $products
     * @param  array<int, Collection<int, stdClass>>  $picks
     * @return non-empty-list<int> id các Dòng xuất vừa chèn, theo thứ tự dòng
     */
    public function deliver(DispatchActor $actor, Dispatch $dispatch, array $lines, EloquentCollection $products, array $picks, DispatchLineKind $kind, string $ledgerReason): array
    {
        $lineIds = self::insertLines($dispatch, $lines, $products, $kind);
        $now = now();
        $transitions = [];

        foreach ($lines as $index => $line) {
            $product = $products[(int) $line->product?->getKey()];
            array_push($transitions, ...SlotPicker::deliver($lineIds[$index], $product, $picks[$index], $actor->user, $now));
        }

        $this->ledger->append($actor->user, $transitions, $ledgerReason, $actor->apiKey);

        return $lineIds;
    }

    /**
     * Chèn Dòng xuất và giữ các Slot đã khoá: Còn hàng → Đã giữ. Phiếu đã ở trạng thái Đang giữ với
     * hạn Giữ hàng của nó; Slot nằm ngoài Tồn bán được cho tới khi được giao hoặc nhả ra.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @param  EloquentCollection<int, Product>  $products
     * @param  array<int, Collection<int, stdClass>>  $picks
     */
    public function hold(DispatchActor $actor, Dispatch $dispatch, array $lines, EloquentCollection $products, array $picks, string $ledgerReason): void
    {
        $lineIds = self::insertLines($dispatch, $lines, $products, DispatchLineKind::Sale);
        $now = now();
        $transitions = [];

        foreach ($lines as $index => $line) {
            array_push($transitions, ...SlotHolds::hold($lineIds[$index], $picks[$index], $now));
        }

        $this->ledger->append($actor->user, $transitions, $ledgerReason, $actor->apiKey);
    }

    /**
     * Xác nhận một phiếu Đang giữ: giao đúng các Slot phiếu đang giữ (Đã giữ → Đã giao) vào chính
     * các Dòng xuất đã chèn lúc giữ, rồi hoàn tất phiếu. Không chọn lại Slot, nên khách nhận đúng
     * phần hàng đã được giữ cho mình.
     */
    public function deliverHeld(DispatchActor $actor, Dispatch $dispatch, string $ledgerReason): void
    {
        $heldByLine = SlotHolds::lockByLine($dispatch);
        $lines = $dispatch->lines()->whereIn('id', array_keys($heldByLine))->with('product')->get();
        $now = now();
        $transitions = [];
        $slotIds = [];

        foreach ($lines as $line) {
            $slots = $heldByLine[$line->id];
            $slotIds = [...$slotIds, ...$slots->map(fn (object $slot): int => (int) $slot->id)->all()];

            array_push($transitions, ...SlotPicker::deliver($line->id, $line->product, $slots, $actor->user, $now, from: SlotStatus::Reserved));
        }

        SlotHolds::forget($slotIds);
        $this->ledger->append($actor->user, $transitions, $ledgerReason, $actor->apiKey);
        $this->complete($dispatch);
    }

    /**
     * Xác nhận một phiếu Hết hạn giữ: Slot cũ đã bị nhả nên chọn lại theo Thứ tự xuất cho đúng các
     * Dòng xuất cũ, giữ đủ hoặc thất bại. Không đủ hàng thì ném {@see OutOfStock} và phiếu ở nguyên
     * Hết hạn giữ để website thử lại sau.
     *
     * @throws InvalidDispatch
     * @throws OutOfStock
     */
    public function deliverAfterHoldExpired(DispatchActor $actor, Dispatch $dispatch, string $ledgerReason): void
    {
        $lines = $dispatch->lines()->where('kind', DispatchLineKind::Sale)->with('product')->get();
        $drafts = $lines->map(fn (DispatchLine $line): DispatchLineDraft => new DispatchLineDraft($line->product, $line->quantity, $line->sale_price))->values()->all();

        [$products, $picks] = SlotPicker::lockAndPick($drafts);

        $now = now();
        $transitions = [];

        foreach ($lines->values() as $index => $line) {
            array_push($transitions, ...SlotPicker::deliver($line->id, $line->product, $picks[$index], $actor->user, $now));
        }

        $this->ledger->append($actor->user, $transitions, $ledgerReason, $actor->apiKey);
        $this->complete($dispatch);
    }

    /**
     * Nhả mọi Slot phiếu đang giữ về Còn hàng và đưa phiếu sang trạng thái mới (Đã huỷ khi website
     * huỷ, Hết hạn giữ khi quá hạn).
     *
     * @param  ?DispatchActor  $actor  null khi hết hạn giữ: đó là việc của kho, không của ai cả
     */
    public function release(?DispatchActor $actor, Dispatch $dispatch, DispatchStatus $status, string $ledgerReason): void
    {
        $this->ledger->append($actor?->user, SlotHolds::release($dispatch, now()), $ledgerReason, $actor?->apiKey);

        $dispatch->forceFill(['status' => $status])->save();
    }

    /**
     * Chèn Dòng xuất của một lần xuất, theo đúng thứ tự dòng.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @param  EloquentCollection<int, Product>  $products
     * @return non-empty-list<int>
     */
    private static function insertLines(Dispatch $dispatch, array $lines, EloquentCollection $products, DispatchLineKind $kind): array
    {
        $lineIds = [];

        foreach ($lines as $line) {
            $product = $products[(int) $line->product?->getKey()];

            $dispatchLine = new DispatchLine;
            $dispatchLine->forceFill([
                'dispatch_id' => $dispatch->id,
                'product_id' => $product->id,
                'kind' => $kind,
                'quantity' => $line->quantity,
                'sale_price' => $line->salePrice,
            ])->save();

            $lineIds[] = $dispatchLine->id;
        }

        assert($lineIds !== []);

        return $lineIds;
    }

    /**
     * Phiếu đã giao xong. Hạn Giữ hàng ở lại như một mốc lịch sử; index nhả hold lọc theo trạng thái
     * nên phiếu Hoàn tất tự rời khỏi tầm quét của job.
     */
    private function complete(Dispatch $dispatch): void
    {
        $dispatch->forceFill(['status' => DispatchStatus::Completed, 'completed_at' => now()])->save();
    }

    /**
     * Lỗi kiểm tra của các Dòng xuất, không tính tồn kho: Sản phẩm, số lượng, Giá bán và giới hạn
     * Slot mỗi phiếu. Dùng chung cho tạo phiếu, Giao thêm và đơn qua API.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @param  int  $deliveredSlots  số Slot phiếu đã giao trước phần này
     * @return list<DispatchProblem>
     */
    public static function lineProblems(array $lines, int $deliveredSlots): array
    {
        $problems = [];
        $products = Product::query()
            ->whereIn('id', array_filter(array_map(fn (DispatchLineDraft $line): ?int => $line->product?->getKey(), $lines)))
            ->get()
            ->keyBy('id');
        $seen = [];
        $slots = $deliveredSlots;

        foreach ($lines as $index => $line) {
            $product = $line->product === null ? null : $products->get($line->product->getKey());

            if ($product === null) {
                $problems[] = new DispatchProblem(sprintf('Dòng xuất thứ %d chưa chọn Sản phẩm.', $index + 1));

                continue;
            }

            if (isset($seen[$product->id])) {
                $problems[] = new DispatchProblem("Sản phẩm \"{$product->name}\" có hai Dòng xuất; mỗi Sản phẩm một Dòng xuất.");

                continue;
            }

            $seen[$product->id] = true;

            if ($product->isDiscontinued()) {
                $problems[] = DispatchProblem::discontinued($product);
            }

            if ($line->quantity < 1) {
                $problems[] = new DispatchProblem("Số lượng của Dòng xuất \"{$product->name}\" phải từ 1 trở lên.");
            }

            if ($line->salePrice !== null && $line->salePrice < 0) {
                $problems[] = new DispatchProblem("Giá bán của Dòng xuất \"{$product->name}\" không được âm.");
            }

            $slots += max(0, $line->quantity);
        }

        if ($slots > self::maxSlots()) {
            $problems[] = new DispatchProblem(sprintf(
                'Phiếu xuất có %s Slot, vượt giới hạn %s Slot mỗi phiếu.',
                number_format($slots, 0, ',', '.'),
                number_format(self::maxSlots(), 0, ',', '.'),
            ));
        }

        return $problems;
    }

    public static function maxSlots(): int
    {
        return (int) config('inventory.dispatch.max_slots');
    }

    /**
     * Mã PX-YYYYMMDD-NNNN theo ngày nghiệp vụ. Bộ đếm cập nhật trong transaction của phiếu nên
     * phiếu thất bại không làm nhảy số; các phiếu tự sinh mã cùng ngày chờ nhau ở bước này.
     */
    private static function nextGeneratedRef(): string
    {
        $today = CarbonImmutable::today();
        $row = DB::selectOne(
            'INSERT INTO dispatch_ref_counters (day, last_number) VALUES (?, 1)
             ON CONFLICT (day) DO UPDATE SET last_number = dispatch_ref_counters.last_number + 1
             RETURNING last_number',
            [$today->toDateString()],
        );

        return sprintf('PX-%s-%04d', $today->format('Ymd'), (int) $row->last_number);
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
