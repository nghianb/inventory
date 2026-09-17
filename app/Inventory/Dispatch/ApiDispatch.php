<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Catalog\UnknownProductCode;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Encryption\KeyFingerprints;
use App\Inventory\Reveal\ContentReveal;
use App\Models\ApiKey;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Product;
use App\Models\SalesChannel;
use Illuminate\Support\Facades\DB;

/**
 * Xuất kho qua API cho Kênh bán loại API. Website đi một trong hai đường, cùng một bộ quy tắc với
 * xuất thủ công (cùng Thứ tự xuất, cùng "giữ đủ hoặc thất bại", cùng Nhật ký xem mã), chỉ khác ở chỗ
 * tác nhân là Khoá API và website tham chiếu Sản phẩm bằng Mã sản phẩm:
 *
 * - **Giữ và giao ngay**: một lần gọi, phiếu Hoàn tất luôn. Dành cho đơn đã thu tiền xong.
 * - **Hai bước**: giữ hàng trong lúc khách thanh toán, rồi {@see confirm()} hoặc {@see cancel()}.
 *   Quá hạn Giữ hàng thì {@see HoldExpiry} nhả Slot và phiếu sang Hết hạn giữ; xác nhận phiếu ấy
 *   vẫn được, kho thử giữ lại hàng.
 *
 * Mã đơn ngoài là khoá idempotency: gửi lại cùng mã với Dòng xuất giống hệt thì nhận lại đúng Phiếu
 * xuất cũ ở trạng thái hiện tại của nó, không giữ hay giao thêm gì. Mã đơn chỉ bị chiếm khi phiếu
 * được tạo, nên đơn hết hàng gửi lại được; đã chiếm rồi thì chiếm vĩnh viễn, kể cả sau khi huỷ.
 */
class ApiDispatch
{
    public function __construct(
        private KeyFingerprints $fingerprints,
        private DispatchWriter $writer,
        private ContentReveal $reveal,
    ) {}

    /**
     * @param  bool  $hold  true thì chỉ Giữ hàng và chờ website xác nhận; false thì giao ngay
     *
     * @throws InvalidDispatch
     * @throws DispatchConflict
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function create(ApiKey $key, ApiOrder $order, bool $hold = false): ApiDispatchResult
    {
        $channel = $key->salesChannel()->firstOrFail();
        $ref = $order->externalRef();
        [$lines, $problems] = self::resolve($channel, $order);

        if ($ref === null) {
            $problems[] = new DispatchProblem('Đơn qua API phải có mã đơn ngoài.');
        } elseif (($problem = ExternalRefs::problem(null, $ref)) !== null) {
            $problems[] = $problem;
        }

        if ($order->lines === []) {
            $problems[] = new DispatchProblem('Phiếu xuất phải có ít nhất một Dòng xuất.');
        }

        $problems = [...$problems, ...DispatchWriter::lineProblems($lines, 0)];

        if ($problems !== []) {
            throw new InvalidDispatch($problems);
        }

        assert($ref !== null);
        $this->fingerprints->verify();

        return DB::transaction(function () use ($key, $channel, $order, $ref, $lines, $hold): ApiDispatchResult {
            $holderId = ExternalRefs::holderId($channel->id, $ref);

            if ($holderId !== null) {
                return $this->replay($key, $holderId, $lines);
            }

            [$products, $picks] = SlotPicker::lockAndPick($lines);
            $actor = DispatchActor::apiKey($key);

            try {
                $dispatch = $this->writer->insert(
                    $actor,
                    $channel,
                    $ref,
                    $order->customer,
                    $order->note,
                    $hold ? DispatchStatus::Holding : DispatchStatus::Completed,
                    $hold ? now()->addMinutes($channel->hold_minutes) : null,
                );
            } catch (InvalidDispatch $exception) {
                // Một request khác vừa chiếm đúng mã đơn này: xử như lần gửi lại, không phải lỗi nhập liệu.
                $holderId = ExternalRefs::holderId($channel->id, $ref);

                if ($holderId === null) {
                    throw $exception;
                }

                return $this->replay($key, $holderId, $lines);
            }

            if ($hold) {
                $this->writer->hold($actor, $dispatch, $lines, $products, $picks, "Giữ hàng theo Phiếu xuất #{$dispatch->id}");
            } else {
                $this->writer->deliver($actor, $dispatch, $lines, $products, $picks, DispatchLineKind::Sale, "Giao hàng theo Phiếu xuất #{$dispatch->id}");
            }

            return $this->result($key, $dispatch, replayed: false);
        }, attempts: 3);
    }

    /**
     * Website xác nhận đơn: phiếu Đang giữ thì giao đúng Slot đã giữ; phiếu Hết hạn giữ thì thử giữ
     * lại hàng rồi giao. Phiếu Hoàn tất trả lại đúng nội dung cũ, không giao thêm — website gọi lại
     * sau khi mất phản hồi vẫn an toàn.
     *
     * @throws DispatchNotFound
     * @throws DispatchConflict
     * @throws InvalidDispatch
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function confirm(ApiKey $key, string $ref): ApiDispatchResult
    {
        $this->fingerprints->verify();

        return DB::transaction(function () use ($key, $ref): ApiDispatchResult {
            $dispatch = self::lock($key, $ref);
            $actor = DispatchActor::apiKey($key);
            $reason = "Giao hàng theo Phiếu xuất #{$dispatch->id}";

            match ($dispatch->status) {
                DispatchStatus::Holding => $this->writer->deliverHeld($actor, $dispatch, $reason),
                DispatchStatus::HoldExpired => $this->writer->deliverAfterHoldExpired($actor, $dispatch, $reason),
                // Đã giao rồi: trả lại nội dung cũ.
                DispatchStatus::Completed => null,
                DispatchStatus::Cancelled => throw self::cancelled($dispatch, 'xác nhận'),
            };

            return $this->result($key, $dispatch->refresh(), replayed: true);
        }, attempts: 3);
    }

    /**
     * Website huỷ đơn: nhả mọi Slot phiếu đang giữ, phiếu sang Đã huỷ. Đã huỷ là trạng thái cuối nên
     * huỷ lại chính phiếu ấy không đổi gì; phiếu đã Hoàn tất thì hàng đã ra khỏi kho, không huỷ được.
     *
     * @throws DispatchNotFound
     * @throws DispatchConflict
     * @throws KeyFingerprintMismatch
     */
    public function cancel(ApiKey $key, string $ref): ApiDispatchResult
    {
        $this->fingerprints->verify();

        return DB::transaction(function () use ($key, $ref): ApiDispatchResult {
            $dispatch = self::lock($key, $ref);

            match ($dispatch->status) {
                DispatchStatus::Holding, DispatchStatus::HoldExpired => $this->writer->release(
                    DispatchActor::apiKey($key),
                    $dispatch,
                    DispatchStatus::Cancelled,
                    "Huỷ Phiếu xuất #{$dispatch->id}",
                ),
                DispatchStatus::Cancelled => null,
                DispatchStatus::Completed => throw new DispatchConflict($dispatch->id, sprintf(
                    'Phiếu xuất #%d đã Hoàn tất; hàng đã giao nên không huỷ được.',
                    $dispatch->id,
                )),
            };

            return $this->result($key, $dispatch->refresh(), replayed: true);
        }, attempts: 3);
    }

    /**
     * Đọc lại phiếu cho trang đơn của khách: thông tin phiếu và **mọi** lần Giao hàng của nó kèm
     * trạng thái, kể cả phần nhân viên Giao thêm, Giao thay hay Đổi hàng sau đó. Nội dung chỉ trả cho
     * lần giao còn hiệu lực và còn trong Hạn bảo hành, và mỗi lần trả ghi Nhật ký xem mã.
     *
     * @throws DispatchNotFound
     * @throws KeyFingerprintMismatch
     */
    public function read(ApiKey $key, string $ref): ApiDispatchResult
    {
        $this->fingerprints->verify();

        return $this->result($key, self::find($key, $ref), replayed: true, everyLine: true);
    }

    /**
     * Mã đơn ngoài đã có phiếu: trả lại đúng phiếu ấy ở trạng thái hiện tại khi Dòng xuất giống hệt.
     * Phiếu Đang giữ và Hết hạn giữ trả về như thế thay vì báo xung đột, để website gửi lại đơn sau
     * khi mất phản hồi vẫn thấy đúng phần hàng đang được giữ cho nó.
     *
     * @param  list<DispatchLineDraft>  $lines
     *
     * @throws DispatchConflict
     */
    private function replay(ApiKey $key, int $dispatchId, array $lines): ApiDispatchResult
    {
        $dispatch = Dispatch::query()->with('lines')->findOrFail($dispatchId);

        if ($dispatch->status === DispatchStatus::Cancelled) {
            throw self::cancelled($dispatch, 'gửi lại');
        }

        if (! self::sameLines($dispatch, $lines)) {
            throw new DispatchConflict($dispatch->id, sprintf(
                'Mã đơn ngoài "%s" đã thuộc Phiếu xuất #%d với Dòng xuất khác.',
                $dispatch->external_ref,
                $dispatch->id,
            ));
        }

        return $this->result($key, $dispatch, replayed: true);
    }

    /**
     * @param  bool  $everyLine  true thì liệt kê mọi Dòng xuất, không chỉ phần website đã gửi
     */
    private function result(ApiKey $key, Dispatch $dispatch, bool $replayed, bool $everyLine = false): ApiDispatchResult
    {
        return new ApiDispatchResult($dispatch, $replayed, $this->reveal->revealApiDispatch($key, $dispatch, $everyLine));
    }

    /**
     * Phiếu của một mã đơn ngoài trong Kênh bán của Khoá API, đã khoá: các thao tác trên cùng một
     * phiếu chạy lần lượt, và job nhả hold không chen vào giữa lúc website đang xác nhận.
     *
     * @throws DispatchNotFound
     */
    private static function lock(ApiKey $key, string $ref): Dispatch
    {
        return Dispatch::query()->lockForUpdate()->findOrFail(self::holderId($key, $ref));
    }

    /**
     * @throws DispatchNotFound
     */
    private static function find(ApiKey $key, string $ref): Dispatch
    {
        return Dispatch::query()->findOrFail(self::holderId($key, $ref));
    }

    /**
     * @throws DispatchNotFound
     */
    private static function holderId(ApiKey $key, string $ref): int
    {
        $ref = trim($ref);
        $channel = $key->salesChannel()->firstOrFail();

        return ExternalRefs::holderId($channel->id, $ref) ?? throw new DispatchNotFound($ref);
    }

    private static function cancelled(Dispatch $dispatch, string $action): DispatchConflict
    {
        return new DispatchConflict($dispatch->id, sprintf(
            'Mã đơn ngoài "%s" thuộc Phiếu xuất #%d đã huỷ; Đã huỷ là trạng thái cuối nên không %s được, và mã đơn đã bị chiếm vĩnh viễn.',
            $dispatch->external_ref,
            $dispatch->id,
            $action,
        ));
    }

    /**
     * Đơn gửi lại có đúng các Dòng xuất của phiếu cũ không: cùng tập (Sản phẩm, số lượng, Giá bán),
     * không xét thứ tự dòng. Chỉ so với Dòng xuất loại Giao bán, vì Giao thêm hay Giao thay là việc
     * nhân viên làm sau đó chứ không thuộc đơn website gửi.
     *
     * @param  list<DispatchLineDraft>  $lines
     */
    private static function sameLines(Dispatch $dispatch, array $lines): bool
    {
        $existing = $dispatch->lines
            ->filter(fn (DispatchLine $line): bool => $line->kind === DispatchLineKind::Sale)
            ->map(fn (DispatchLine $line): array => [$line->product_id, $line->quantity, $line->sale_price])
            ->sort()
            ->values()
            ->all();

        $incoming = collect($lines)
            ->map(fn (DispatchLineDraft $line): array => [(int) $line->product?->getKey(), $line->quantity, $line->salePrice])
            ->sort()
            ->values()
            ->all();

        return $existing === $incoming;
    }

    /**
     * Dòng xuất của đơn, theo Mã sản phẩm, kèm các lỗi của từng dòng. Mã không có trong kho thành lỗi
     * và dòng ấy bị bỏ ra, nên mọi kiểm tra theo dòng phải làm ngay tại đây: sau vòng lặp này thứ tự
     * dòng đã lệch khỏi đơn website gửi.
     *
     * @return array{list<DispatchLineDraft>, list<DispatchProblem>}
     */
    private static function resolve(SalesChannel $channel, ApiOrder $order): array
    {
        $codes = array_values(array_unique(array_map(fn (ApiOrderLine $line): string => $line->productCode, $order->lines)));
        $products = Product::query()->whereIn('code', $codes)->get()->keyBy('code');
        $lines = [];
        $problems = [];

        foreach ($order->lines as $line) {
            $product = $products->get($line->productCode);

            if ($product === null) {
                $problems[] = new DispatchProblem(UnknownProductCode::message($line->productCode));

                continue;
            }

            // Kênh bắt buộc Giá bán thì mọi Dòng xuất phải có, để báo cáo Lãi/lỗ của đơn web không hổng.
            if ($channel->requires_sale_price && $line->salePrice === null) {
                $problems[] = new DispatchProblem("Kênh bán \"{$channel->name}\" bắt buộc Giá bán; Dòng xuất \"{$line->productCode}\" chưa có.");
            }

            $lines[] = new DispatchLineDraft($product, $line->quantity, $line->salePrice);
        }

        return [$lines, $problems];
    }
}
