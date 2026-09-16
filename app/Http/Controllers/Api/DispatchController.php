<?php

namespace App\Http\Controllers\Api;

use App\Http\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Inventory\Dispatch\ApiDispatch;
use App\Inventory\Dispatch\ApiDispatchResult;
use App\Inventory\Dispatch\ApiOrder;
use App\Inventory\Dispatch\ApiOrderLine;
use App\Inventory\Dispatch\DeliveredContent;
use App\Inventory\Dispatch\DispatchConflict;
use App\Inventory\Dispatch\DispatchLineKind;
use App\Inventory\Dispatch\DispatchProblem;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\OutOfStock;
use App\Inventory\Dispatch\Shortage;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Models\DispatchLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Đơn của website: giữ và giao ngay trong một lần gọi, trả nội dung từng Slot. Adapter mỏng — mọi
 * quy tắc (idempotency theo mã đơn ngoài, giữ đủ hoặc thất bại, Nhật ký xem mã) nằm trong
 * {@see ApiDispatch}; ở đây chỉ dựng input và đổi lỗi nghiệp vụ thành mã HTTP.
 */
class DispatchController extends Controller
{
    public function store(Request $request, ApiDispatch $dispatches): JsonResponse
    {
        $order = self::orderFrom($request, $problems);

        if ($problems !== []) {
            return ApiProblem::invalid($problems);
        }

        try {
            $result = $dispatches->create(AuthenticateApiKey::key($request), $order);
        } catch (InvalidDispatch $exception) {
            return ApiProblem::invalid(array_map(fn (DispatchProblem $problem): string => $problem->message, $exception->problems));
        } catch (OutOfStock $exception) {
            return ApiProblem::response(409, 'out_of_stock', $exception->getMessage(), ['shortages' => self::shortages($exception)]);
        } catch (DispatchConflict $exception) {
            return ApiProblem::response(409, 'dispatch_conflict', $exception->getMessage(), ['dispatch_id' => $exception->dispatchId]);
        } catch (KeyFingerprintMismatch $exception) {
            // Khoá mã hoá không khớp DB: kho từ chối mọi đường ghi cho tới khi Quản trị xử lý.
            return ApiProblem::response(503, 'inventory_unavailable', $exception->getMessage());
        }

        return response()->json(self::payload($result), $result->replayed ? 200 : 201);
    }

    /**
     * Đơn từ thân request. Chỉ kiểm hình dạng JSON; đúng sai nghiệp vụ để module Kho trả lời.
     *
     * @param  list<string>  $problems
     *
     * @param-out list<string>  $problems
     */
    private static function orderFrom(Request $request, ?array &$problems): ApiOrder
    {
        $problems = [];
        $lines = $request->input('lines', []);
        $orderLines = [];

        if (! is_array($lines)) {
            $problems[] = 'Trường "lines" phải là danh sách Dòng xuất.';
            $lines = [];
        }

        foreach (array_values($lines) as $index => $line) {
            $number = $index + 1;
            $code = is_array($line) ? ($line['product_code'] ?? null) : null;
            $quantity = is_array($line) ? ($line['quantity'] ?? null) : null;
            $salePrice = is_array($line) ? ($line['sale_price'] ?? null) : null;

            if (! is_string($code) || trim($code) === '') {
                $problems[] = "Dòng xuất thứ {$number} thiếu Mã sản phẩm.";

                continue;
            }

            if (! self::isWholeNumber($quantity)) {
                $problems[] = "Số lượng của Dòng xuất thứ {$number} phải là số nguyên.";

                continue;
            }

            if ($salePrice !== null && ! self::isWholeNumber($salePrice)) {
                $problems[] = "Giá bán của Dòng xuất thứ {$number} phải là số nguyên (VND).";

                continue;
            }

            $orderLines[] = new ApiOrderLine(trim($code), (int) $quantity, $salePrice === null ? null : (int) $salePrice);
        }

        foreach (['external_ref', 'customer', 'note'] as $field) {
            if ($request->input($field) !== null && ! is_string($request->input($field))) {
                $problems[] = "Trường \"{$field}\" phải là chuỗi.";
            }
        }

        return new ApiOrder(
            externalRef: is_string($request->input('external_ref')) ? $request->input('external_ref') : null,
            lines: $orderLines,
            customer: is_string($request->input('customer')) ? $request->input('customer') : null,
            note: is_string($request->input('note')) ? $request->input('note') : null,
        );
    }

    /**
     * Tiền VND và số Slot luôn là số nguyên; chuỗi số của website cũng nhận.
     */
    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    /**
     * Từng Dòng xuất thiếu hàng, theo Mã sản phẩm để website đối chiếu với đơn đã gửi.
     *
     * @return list<array{product_code: string, needed: int, available: int}>
     */
    private static function shortages(OutOfStock $exception): array
    {
        return array_map(fn (Shortage $shortage): array => [
            'product_code' => $shortage->productCode,
            'needed' => $shortage->needed,
            'available' => $shortage->available,
        ], $exception->shortages);
    }

    /**
     * Phiếu xuất trả cho website: thông tin đơn, các Dòng xuất của đơn và nội dung từng Slot đã giao.
     *
     * @return array<string, mixed>
     */
    private static function payload(ApiDispatchResult $result): array
    {
        $dispatch = $result->dispatch->load(['lines.product', 'salesChannel']);

        return ['dispatch' => [
            'id' => $dispatch->id,
            'external_ref' => $dispatch->external_ref,
            'status' => $dispatch->status->value,
            'sales_channel' => $dispatch->salesChannel->name,
            'created_at' => $dispatch->created_at?->toIso8601String(),
            'lines' => $dispatch->lines
                ->filter(fn (DispatchLine $line): bool => $line->kind === DispatchLineKind::Sale)
                ->map(fn (DispatchLine $line): array => [
                    'product_code' => $line->product->code,
                    'quantity' => $line->quantity,
                    'sale_price' => $line->sale_price,
                ])
                ->values()
                ->all(),
            'deliveries' => array_map(fn (DeliveredContent $slot): array => [
                'id' => $slot->deliveryId,
                'product_code' => $slot->productCode,
                'text' => $slot->message,
                'fields' => $slot->values,
                'expires_on' => $slot->expiresOn?->toDateString(),
                'warranty_ends_on' => $slot->warrantyEndsOn->toDateString(),
            ], $result->slots),
        ]];
    }
}
