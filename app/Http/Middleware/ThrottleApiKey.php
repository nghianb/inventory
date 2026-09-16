<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit cơ bản theo từng Khoá API: một khoá bị lộ không rút sạch kho ngay, và một kênh gọi
 * nhiều không ăn mất hạn mức của kênh khác. Chạy sau {@see AuthenticateApiKey} vì hạn mức tính
 * theo khoá chứ không theo IP; request chưa qua xác thực đã có trần riêng ở middleware ấy.
 */
class ThrottleApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bucket = 'inventory-api:'.AuthenticateApiKey::key($request)->getKey();
        $limit = (int) config('inventory.api.rate_limit_per_minute');

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            return ApiProblem::rateLimited(RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket);

        return $next($request);
    }
}
