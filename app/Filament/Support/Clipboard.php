<?php

namespace App\Filament\Support;

use Illuminate\Support\Js;

/**
 * Copy vào clipboard ở trình duyệt, không đi server. Dùng cho nội dung đã nằm sẵn trong trang;
 * nội dung phải gọi server mới lấy được thì bấm xong mới gọi handler này với chuỗi trả về.
 */
final class Clipboard
{
    /**
     * Handler Alpine cho `alpineClickHandler()` hoặc `$this->js()`.
     */
    public static function copy(string $text, string $notification): string
    {
        return sprintf(
            'window.navigator.clipboard.writeText(%s).then(() => new FilamentNotification().title(%s).success().send())',
            Js::from($text),
            Js::from($notification),
        );
    }
}
