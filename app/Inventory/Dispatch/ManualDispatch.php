<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Stock\SellableStock;
use App\Inventory\Stock\SlotStatus;
use App\Inventory\Stock\StockLedger;
use App\Inventory\Stock\StockTransition;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Xuất kho thủ công: nhân viên tạo Phiếu xuất cho một đơn, hệ thống chọn Slot theo Thứ tự xuất
 * và giao ngay trong một transaction. Cả phiếu giao đủ hoặc thất bại. Slot được chọn bằng
 * `FOR UPDATE SKIP LOCKED`, nên hai tiến trình xuất cùng lúc không bao giờ chọn trùng Slot.
 */
class ManualDispatch
{
    public const MAX_REF_LENGTH = 100;

    private const GENERATED_REF_ATTEMPTS = 50;

    public function __construct(
        private RoleGate $roles,
        private KeyFingerprints $fingerprints,
        private SellableStock $stock,
        private StockLedger $ledger,
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
     * Dòng xuất thiếu hàng theo Tồn bán được lúc này, cho modal xác nhận. Không khoá gì, nên
     * {@see create()} vẫn có thể báo thiếu hàng khi phiếu khác vừa lấy mất.
     *
     * @return list<Shortage>
     *
     * @throws MissingRole
     */
    public function shortages(User $actor, DispatchDraft $draft): array
    {
        $this->roles->authorize($actor, Role::BanHang);

        $lines = array_values(array_filter($draft->lines, fn (DispatchLineDraft $line): bool => $line->product !== null && $line->quantity > 0));
        $available = $this->stock->counts(array_values(array_unique(array_map(fn (DispatchLineDraft $line): int => (int) $line->product?->getKey(), $lines))));
        $shortages = [];

        foreach ($lines as $line) {
            $product = $line->product;
            assert($product !== null);

            if ($line->quantity > $available[(int) $product->getKey()]) {
                $shortages[] = new Shortage((int) $product->getKey(), $product->name, $line->quantity, $available[(int) $product->getKey()]);
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

            // Khoá chia sẻ theo thứ tự id: Quản trị không Ngừng bán hay đổi Mã sản phẩm, thời hạn
            // bảo hành giữa lúc kiểm tra và lúc giao; hai phiếu cùng Sản phẩm vẫn chạy song song.
            $products = Product::query()
                ->whereIn('id', array_map(fn (DispatchLineDraft $line): int => (int) $line->product?->getKey(), $draft->lines))
                ->orderBy('id')
                ->sharedLock()
                ->get()
                ->keyBy('id');

            $discontinued = $products->filter(fn (Product $product): bool => $product->isDiscontinued());

            if ($discontinued->isNotEmpty()) {
                throw new InvalidDispatch($discontinued->map(fn (Product $product): DispatchProblem => self::discontinued($product))->values()->all());
            }

            $today = CarbonImmutable::today();
            $picks = [];
            $shortages = [];

            foreach ($draft->lines as $index => $line) {
                $product = $products[(int) $line->product?->getKey()];
                $picks[$index] = self::pick($product, $line->quantity, $today);

                if ($picks[$index]->count() < $line->quantity) {
                    $shortages[] = new Shortage($product->id, $product->name, $line->quantity, $picks[$index]->count());
                }
            }

            if ($shortages !== []) {
                throw new OutOfStock($shortages);
            }

            $dispatch = $this->insertDispatch($actor, $channel, $draft);
            $now = now();
            $transitions = [];

            foreach ($draft->lines as $index => $line) {
                $product = $products[(int) $line->product?->getKey()];

                $dispatchLine = new DispatchLine;
                $dispatchLine->forceFill([
                    'dispatch_id' => $dispatch->id,
                    'product_id' => $product->id,
                    'kind' => DispatchLineKind::Sale,
                    'quantity' => $line->quantity,
                    'sale_price' => $line->salePrice,
                ])->save();

                DB::table('slots')
                    ->whereIn('id', $picks[$index]->pluck('id'))
                    ->update(['status' => SlotStatus::Delivered->value, 'updated_at' => $now]);

                DB::table('deliveries')->insert($picks[$index]->map(fn (object $slot): array => [
                    'dispatch_line_id' => $dispatchLine->id,
                    'slot_id' => $slot->id,
                    'stock_unit_id' => $slot->stock_unit_id,
                    'warranty_days' => $product->warranty_days,
                    'delivered_at' => $now,
                    'delivered_by' => $actor->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                foreach ($picks[$index] as $slot) {
                    $transitions[] = new StockTransition((int) $slot->stock_unit_id, (int) $slot->id, SlotStatus::InStock, SlotStatus::Delivered);
                }
            }

            $this->ledger->append($actor, $transitions, "Giao hàng theo Phiếu xuất #{$dispatch->id}");

            return $dispatch;
        }, attempts: 3);
    }

    /**
     * Chọn và khoá Slot theo Thứ tự xuất: Tài khoản đã giao dở trước, rồi Hạn sử dụng gần nhất
     * (không có hạn xếp sau), rồi hàng nhập trước. Slot đang bị giao dịch khác khoá thì bỏ qua;
     * Đơn vị hàng bị khoá chia sẻ để không đổi trạng thái trước khi giao xong.
     *
     * @return Collection<int, stdClass> các hàng `id`, `stock_unit_id` của Slot đã khoá
     */
    private static function pick(Product $product, int $quantity, CarbonImmutable $today): Collection
    {
        return SellableStock::slots($today)
            ->where('stock_units.product_id', $product->id)
            ->select('slots.id', 'slots.stock_unit_id')
            ->orderByRaw(
                'EXISTS (SELECT 1 FROM slots delivered WHERE delivered.stock_unit_id = stock_units.id AND delivered.status = ?) DESC',
                [SlotStatus::Delivered->value],
            )
            ->orderByRaw('stock_units.expires_on ASC NULLS LAST')
            ->orderBy('stock_units.id')
            ->orderBy('slots.id')
            ->limit($quantity)
            ->lock('FOR UPDATE OF slots SKIP LOCKED FOR SHARE OF stock_units SKIP LOCKED')
            ->get();
    }

    /**
     * Chiếm mã đơn ngoài (ON CONFLICT, không làm hỏng transaction) rồi mới chèn phiếu với id lấy trước:
     * mã nhập tay đã bị chiếm thì báo trùng, mã tự sinh đã bị chiếm (nhân viên từng gõ hoặc sửa phiếu
     * sang đúng mã đó) thì lấy số kế.
     *
     * @throws InvalidDispatch
     */
    private function insertDispatch(User $actor, SalesChannel $channel, DispatchDraft $draft): Dispatch
    {
        $ref = $draft->externalRef();

        for ($attempt = 0; $attempt < self::GENERATED_REF_ATTEMPTS; $attempt++) {
            $candidate = $ref ?? self::nextGeneratedRef();
            $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('dispatches', 'id')) AS id")->id;

            if (! ExternalRefs::claim($channel->id, $candidate, $id)) {
                if ($ref !== null) {
                    throw new InvalidDispatch([self::duplicateRef($channel, $ref)]);
                }

                continue;
            }

            $now = now();

            DB::table('dispatches')->insert([
                'id' => $id,
                'sales_channel_id' => $channel->id,
                'external_ref' => $candidate,
                'customer' => self::blankToNull($draft->customer),
                'note' => self::blankToNull($draft->note),
                'status' => DispatchStatus::Completed->value,
                'created_by' => $actor->getKey(),
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return Dispatch::query()->findOrFail($id);
        }

        throw new InvalidDispatch([new DispatchProblem('Không sinh được mã đơn ngoài; hãy nhập mã đơn.')]);
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
        }

        if ($channel !== null && $ref === null && $channel->requires_external_ref) {
            $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" bắt buộc mã đơn ngoài.");
        }

        if ($ref !== null && mb_strlen($ref) > self::MAX_REF_LENGTH) {
            $problems[] = new DispatchProblem(sprintf('Mã đơn ngoài dài quá %d ký tự.', self::MAX_REF_LENGTH));
        } elseif ($channel !== null && $ref !== null && self::existingDispatchId($channel, $ref) !== null) {
            $problems[] = self::duplicateRef($channel, $ref);
        }

        if ($draft->lines === []) {
            $problems[] = new DispatchProblem('Phiếu xuất phải có ít nhất một Dòng xuất.');
        }

        $products = Product::query()
            ->whereIn('id', array_filter(array_map(fn (DispatchLineDraft $line): ?int => $line->product?->getKey(), $draft->lines)))
            ->get()
            ->keyBy('id');
        $seen = [];
        $slots = 0;

        foreach ($draft->lines as $index => $line) {
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
                $problems[] = self::discontinued($product);
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

    public static function duplicateRef(SalesChannel $channel, string $ref): DispatchProblem
    {
        return new DispatchProblem("Mã đơn ngoài \"{$ref}\" đã có trong Kênh bán \"{$channel->name}\".", self::existingDispatchId($channel, $ref));
    }

    private static function existingDispatchId(SalesChannel $channel, string $ref): ?int
    {
        return ExternalRefs::holderId($channel->id, $ref);
    }

    private static function discontinued(Product $product): DispatchProblem
    {
        return new DispatchProblem("Sản phẩm \"{$product->name}\" đã Ngừng bán.");
    }

    public static function maxSlots(): int
    {
        return (int) config('inventory.dispatch.max_slots');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
