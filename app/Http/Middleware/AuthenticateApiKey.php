<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiProblem;
use App\Inventory\Api\ApiKeys;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xác thực một request của website bằng Khoá API trong header `Authorization: Bearer <khoá>`.
 * Khoá sai, đã thu hồi, hay thuộc Kênh bán đã ngừng dùng đều là 401; không phân biệt lý do trong
 * phản hồi để khoá đúng và khoá sai không lộ ra khác nhau.
 *
 * Request không qua được xác thực chịu rate limit theo IP: rate limit theo Khoá API
 * ({@see ThrottleApiKey}) chỉ đếm được khi đã biết khoá nào, nên nếu không có bước này thì
 * đường gửi khoá sai là đường duy nhất không có trần.
 */
class AuthenticateApiKey
{
    private const ATTRIBUTE = 'inventory.api_key';

    public function __construct(private ApiKeys $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        $key = $secret === null ? null : $this->keys->authenticate($secret);

        if ($key === null) {
            return self::refuse($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        return $next($request);
    }

    /**
     * Khoá API đã xác thực của request. Chỉ gọi sau middleware này.
     */
    public static function key(Request $request): ApiKey
    {
        $key = $request->attributes->get(self::ATTRIBUTE);

        assert($key instanceof ApiKey);

        return $key;
    }

    private static function refuse(Request $request): Response
    {
        $bucket = 'inventory-api-auth:'.$request->ip();
        $limit = (int) config('inventory.api.rate_limit_per_minute');

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            return ApiProblem::rateLimited(RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket);

        return ApiProblem::unauthorized();
    }
}
