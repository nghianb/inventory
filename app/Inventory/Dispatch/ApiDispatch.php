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
 * Xuất kho qua API cho Kênh bán loại API: website gửi một đơn, kho giữ và giao ngay trong một lần
 * gọi. Dùng chung module Kho với xuất thủ công — cùng Thứ tự xuất, cùng "giữ đủ hoặc thất bại",
 * cùng Nhật ký xem mã — chỉ khác ở chỗ tác nhân là Khoá API và website tham chiếu Sản phẩm bằng
 * Mã sản phẩm.
 *
 * Mã đơn ngoài là khoá idempotency: gửi lại cùng mã với Dòng xuất giống hệt thì nhận lại đúng
 * Phiếu xuất cũ và nội dung cũ (chỉ các lần giao còn trong Hạn bảo hành), không giao thêm; Dòng
 * xuất khác thì xung đột. Mã đơn chỉ bị chiếm khi phiếu được tạo, nên đơn hết hàng gửi lại được.
 */
class ApiDispatch
{
    public function __construct(
        private KeyFingerprints $fingerprints,
        private DispatchWriter $writer,
        private ContentReveal $reveal,
    ) {}

    /**
     * @throws InvalidDispatch
     * @throws DispatchConflict
     * @throws OutOfStock
     * @throws KeyFingerprintMismatch
     */
    public function create(ApiKey $key, ApiOrder $order): ApiDispatchResult
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

        return DB::transaction(function () use ($key, $channel, $order, $ref, $lines): ApiDispatchResult {
            $holderId = ExternalRefs::holderId($channel->id, $ref);

            if ($holderId !== null) {
                return $this->replay($key, $holderId, $lines);
            }

            [$products, $picks] = SlotPicker::lockAndPick($lines);
            $actor = DispatchActor::apiKey($key);

            try {
                $dispatch = $this->writer->insert($actor, $channel, $ref, $order->customer, $order->note);
            } catch (InvalidDispatch $exception) {
                // Một request khác vừa chiếm đúng mã đơn này: xử như lần gửi lại, không phải lỗi nhập liệu.
                $holderId = ExternalRefs::holderId($channel->id, $ref);

                if ($holderId === null) {
                    throw $exception;
                }

                return $this->replay($key, $holderId, $lines);
            }

            $this->writer->deliver($actor, $dispatch, $lines, $products, $picks, DispatchLineKind::Sale, "Giao hàng theo Phiếu xuất #{$dispatch->id}");

            return new ApiDispatchResult($dispatch, false, $this->reveal->revealApiDispatch($key, $dispatch));
        }, attempts: 3);
    }

    /**
     * Mã đơn ngoài đã có phiếu: trả lại đúng phiếu ấy khi Dòng xuất giống hệt, còn không thì xung đột.
     *
     * Phiếu chưa Hoàn tất bị coi là xung đột vì #32 chỉ có đường giữ-và-giao-ngay nên mọi phiếu API
     * đều Hoàn tất ngay; khi có luồng hai bước (#33), trạng thái Đang giữ và Hết hạn giữ phải trả về
     * phiếu hiện có ở trạng thái hiện tại thay vì xung đột.
     *
     * @param  list<DispatchLineDraft>  $lines
     *
     * @throws DispatchConflict
     */
    private function replay(ApiKey $key, int $dispatchId, array $lines): ApiDispatchResult
    {
        $dispatch = Dispatch::query()->with('lines')->findOrFail($dispatchId);

        if ($dispatch->status !== DispatchStatus::Completed) {
            throw new DispatchConflict($dispatch->id, sprintf(
                'Mã đơn ngoài "%s" thuộc Phiếu xuất #%d đang ở trạng thái %s; mã đơn đã bị chiếm vĩnh viễn.',
                $dispatch->external_ref,
                $dispatch->id,
                $dispatch->status->label(),
            ));
        }

        if (! self::sameLines($dispatch, $lines)) {
            throw new DispatchConflict($dispatch->id, sprintf(
                'Mã đơn ngoài "%s" đã thuộc Phiếu xuất #%d với Dòng xuất khác.',
                $dispatch->external_ref,
                $dispatch->id,
            ));
        }

        return new ApiDispatchResult($dispatch, true, $this->reveal->revealApiDispatch($key, $dispatch));
    }

    /**
     * Đơn gửi lại có đúng các Dòng xuất của lần đã giao không: cùng tập (Sản phẩm, số lượng, Giá bán),
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
