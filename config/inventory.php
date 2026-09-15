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

    /*
    |--------------------------------------------------------------------------
    | Nhập hàng
    |--------------------------------------------------------------------------
    |
    | Giới hạn mỗi file hoặc danh sách dán (con số tạm, chốt lại sau khi đo trên VPS).
    | Nội dung chờ xác nhận nằm trên disk riêng ở ổ local, mã hoá, không vào backup DB;
    | Lô nhập chưa xác nhận quá `pending_ttl_hours` thì hết hạn và nội dung tạm bị xoá.
    |
    */

    'intake' => [
        'max_lines' => (int) env('INVENTORY_INTAKE_MAX_LINES', 20_000),
        'max_bytes' => (int) env('INVENTORY_INTAKE_MAX_BYTES', 10 * 1024 * 1024),
        'pending_ttl_hours' => (int) env('INVENTORY_INTAKE_PENDING_TTL_HOURS', 24),
        'disk' => 'intake',
    ],

];
