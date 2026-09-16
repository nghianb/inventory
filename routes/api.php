<?php

use App\Http\Controllers\Api\DispatchController;
use App\Http\Controllers\Api\StockController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ThrottleApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API xuất kho cho website
|--------------------------------------------------------------------------
|
| Hợp đồng để website tự lấy hàng mà không giao trùng với nhân viên bán thủ công. Mỗi request
| xác thực bằng Khoá API của một Kênh bán loại API và chịu rate limit riêng theo khoá đó.
|
| Đường dẫn và khoá JSON dùng tiếng Anh như tên cột trong DB (`product_code`, `external_ref`,
| `sale_price`), còn thông báo lỗi là tiếng Việt theo thuật ngữ trong CONTEXT.md.
|
*/

Route::prefix('v1')
    ->middleware([AuthenticateApiKey::class, ThrottleApiKey::class])
    ->group(function (): void {
        // Số Slot Tồn bán được theo Mã sản phẩm. Chỉ tham khảo, không giữ hàng.
        Route::get('stock', StockController::class);

        // Một đơn của website: giữ và giao ngay. Idempotent theo (Kênh bán, mã đơn ngoài).
        Route::post('dispatches', [DispatchController::class, 'store']);
    });
