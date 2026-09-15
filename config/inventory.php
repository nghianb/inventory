<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Khoá mã hoá của kho
    |--------------------------------------------------------------------------
    |
    | Tách khỏi APP_KEY (ADR 0001). Mỗi khoá viết dạng "<phiên bản>:base64:<32 byte>",
    | ví dụ "1:base64:...". Chỉ khoá nội dung có danh sách khoá cũ (phân tách bằng
    | dấu phẩy) để giải mã bản ghi chưa mã hoá lại sau khi xoay khoá.
    |
    */

    'keys' => [
        'content' => env('INVENTORY_CONTENT_KEY'),
        'content_previous' => array_values(array_filter(
            explode(',', (string) env('INVENTORY_CONTENT_PREVIOUS_KEYS', ''))
        )),
        'hmac' => env('INVENTORY_HMAC_KEY'),
        'backup' => env('INVENTORY_BACKUP_KEY'),
    ],

];
