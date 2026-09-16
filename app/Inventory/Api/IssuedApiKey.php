<?php

namespace App\Inventory\Api;

use App\Models\ApiKey;
use SensitiveParameter;

/**
 * Khoá API vừa cấp. Giá trị khoá chỉ sống trong bộ nhớ của lần cấp này: kho lưu hash, nên đây là
 * lần duy nhất Quản trị đọc được nó.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        #[SensitiveParameter] public string $secret,
    ) {}
}
