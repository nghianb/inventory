<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;

/**
 * Lỗi trả cho website, một hình dạng duy nhất cho mọi endpoint:
 *
 *     {"error": {"code": "...", "message": "...", ...}}
 *
 * `code` là thứ website nên rẽ nhánh theo; `message` là tiếng Việt cho người đọc log. Tuỳ loại lỗi
 * có thêm `problems` (lỗi kiểm tra), `shortages` (hết hàng) hay `dispatch_id` (xung đột mã đơn).
 */
final class ApiProblem
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function response(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status);
    }

    /**
     * Đơn hoặc tham số không hợp lệ: website sửa rồi gửi lại được, kho chưa đụng gì tới hàng.
     *
     * @param  list<string>  $problems
     */
    public static function invalid(array $problems): JsonResponse
    {
        return self::response(422, 'invalid_request', 'Yêu cầu không hợp lệ.', ['problems' => $problems]);
    }

    /**
     * Mã đơn ngoài chưa thuộc Phiếu xuất nào trong Kênh bán của Khoá API: website gửi đơn trước đã.
     */
    public static function dispatchNotFound(string $ref): JsonResponse
    {
        return self::response(404, 'dispatch_not_found', "Không có Phiếu xuất nào với mã đơn ngoài \"{$ref}\".");
    }

    public static function unauthorized(): JsonResponse
    {
        return self::response(401, 'unauthorized', 'Khoá API không hợp lệ hoặc đã bị thu hồi.');
    }

    /**
     * Gọi quá nhanh: website chờ `Retry-After` giây rồi thử lại.
     */
    public static function rateLimited(int $seconds): JsonResponse
    {
        return self::response(429, 'rate_limited', "Gọi quá nhiều; thử lại sau {$seconds} giây.")
            ->header('Retry-After', (string) $seconds);
    }
}
