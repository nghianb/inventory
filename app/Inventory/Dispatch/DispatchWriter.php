<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Stock\StockLedger;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\SalesChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Phần ghi chung của các đường xuất kho giao ngay (panel và API): chiếm mã đơn ngoài, chèn Phiếu
 * xuất, chèn Dòng xuất, giao các Slot đã khoá và ghi Sổ biến động kho. Người gọi chạy trong
 * transaction, đã kiểm tra nghiệp vụ và đã khoá Slot bằng {@see SlotPicker::lockAndPick()}.
 */
final class DispatchWriter
{
    private const GENERATED_REF_ATTEMPTS = 50;

    public function __construct(private StockLedger $ledger) {}

    /**
     * Chiếm mã đơn ngoài (ON CONFLICT, không làm hỏng transaction) rồi mới chèn phiếu với id lấy trước:
     * mã nhập tay đã bị chiếm thì báo trùng, mã tự sinh đã bị chiếm (nhân viên từng gõ hoặc sửa phiếu
     * sang đúng mã đó) thì lấy số kế. Phiếu chèn ra đã Hoàn tất.
     *
     * @param  ?string  $ref  null thì tự sinh mã theo ngày
     *
     * @throws InvalidDispatch
     */
    public function insert(DispatchActor $actor, SalesChannel $channel, ?string $ref, ?string $customer, ?string $note): Dispatch
    {
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
                'status' => DispatchStatus::Completed->value,
                'created_by' => $actor->user?->getKey(),
                'created_by_api_key_id' => $actor->apiKey?->getKey(),
                // Màn kết quả xuất kho là màn của nhân viên: phiếu do Khoá API tạo không có ai xem.
                'result_by' => $actor->user?->getKey(),
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Dispatch::query()->findOrFail($id);
        }

        throw new InvalidDispatch([new DispatchProblem('Không sinh được mã đơn ngoài; hãy nhập mã đơn.')]);
    }

    /**
     * Chèn Dòng xuất và giao các Slot đã khoá, ghi Sổ biến động kho với tác nhân của lần xuất.
     *
     * @param  list<DispatchLineDraft>  $lines
     * @param  EloquentCollection<int, Product>  $products
     * @param  array<int, Collection<int, stdClass>>  $picks
     * @return non-empty-list<int> id các Dòng xuất vừa chèn, theo thứ tự dòng
     */
    public function deliver(DispatchActor $actor, Dispatch $dispatch, array $lines, EloquentCollection $products, array $picks, DispatchLineKind $kind, string $ledgerReason): array
    {
        $now = now();
        $transitions = [];
        $lineIds = [];

        foreach ($lines as $index => $line) {
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

            array_push($transitions, ...SlotPicker::deliver($dispatchLine->id, $product, $picks[$index], $actor->user, $now));
        }

        assert($lineIds !== []);

        $this->ledger->append($actor->user, $transitions, $ledgerReason, $actor->apiKey);

        return $lineIds;
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
