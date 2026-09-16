<?php

namespace App\Http\Controllers\Api;

use App\Http\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Inventory\Catalog\UnknownProductCode;
use App\Inventory\Stock\SellableStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kiểm tra tồn cho website: số Slot Tồn bán được theo Mã sản phẩm. Chỉ tham khảo, không giữ hàng,
 * nên con số có thể đổi trước khi website gửi đơn. Adapter mỏng: cả định nghĩa Tồn bán được lẫn việc
 * tra Mã sản phẩm đều nằm trong module Kho, dùng chung với form xuất kho và báo cáo Tồn kho.
 */
class StockController extends Controller
{
    public function __invoke(Request $request, SellableStock $stock): JsonResponse
    {
        $codes = $request->query('product_codes');

        if (! is_array($codes) || $codes === []) {
            return ApiProblem::invalid(['Tham số "product_codes" phải là danh sách Mã sản phẩm.']);
        }

        foreach ($codes as $code) {
            if (! is_string($code) || trim($code) === '') {
                return ApiProblem::invalid(['Tham số "product_codes" phải là danh sách Mã sản phẩm.']);
            }
        }

        $codes = array_values(array_map(trim(...), $codes));

        try {
            $available = $stock->countsByCode($codes);
        } catch (UnknownProductCode $exception) {
            return ApiProblem::invalid($exception->problems());
        }

        return response()->json(['stock' => array_map(fn (string $code): array => [
            'product_code' => $code,
            'available' => $available[$code],
        ], $codes)]);
    }
}
