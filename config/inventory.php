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
    | Dòng bị bỏ tải được ở màn xem trước và trong `rejected_download_minutes` phút ngay
    | sau khi xác nhận; sau đó bị xoá.
    |
    */

    'intake' => [
        'max_lines' => (int) env('INVENTORY_INTAKE_MAX_LINES', 20_000),
        'max_bytes' => (int) env('INVENTORY_INTAKE_MAX_BYTES', 10 * 1024 * 1024),
        'pending_ttl_hours' => (int) env('INVENTORY_INTAKE_PENDING_TTL_HOURS', 24),
        'rejected_download_minutes' => (int) env('INVENTORY_INTAKE_REJECTED_DOWNLOAD_MINUTES', 30),
        'disk' => 'intake',
    ],

    /*
    |--------------------------------------------------------------------------
    | Xuất kho
    |--------------------------------------------------------------------------
    |
    | Số Slot tối đa của một Phiếu xuất (tổng mọi Dòng xuất). Phiếu có từ `result_mask_slots`
    | Slot trở lên thì màn kết quả chỉ hiện dạng che. Người tạo phiếu Copy tất cả (khi dạng che)
    | và tải TXT/CSV trong `result_download_minutes` phút sau khi màn kết quả hiện. Bán hàng
    | Giao thay trong `corrective_hours` giờ kể từ lúc giao; quá hạn chỉ Quản trị kèm lý do.
    |
    */

    'dispatch' => [
        'max_slots' => (int) env('INVENTORY_DISPATCH_MAX_SLOTS', 1_000),
        'result_mask_slots' => (int) env('INVENTORY_DISPATCH_RESULT_MASK_SLOTS', 50),
        'result_download_minutes' => (int) env('INVENTORY_DISPATCH_RESULT_DOWNLOAD_MINUTES', 30),
        'corrective_hours' => (int) env('INVENTORY_DISPATCH_CORRECTIVE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | API xuất kho cho website
    |--------------------------------------------------------------------------
    |
    | Hạn Giữ hàng mặc định của một Kênh bán loại API mới (phút); mỗi kênh đặt riêng được.
    | Rate limit tính theo từng Khoá API, mỗi phút, để khoá bị lộ không rút sạch kho ngay.
    |
    */

    'api' => [
        'hold_minutes' => (int) env('INVENTORY_API_HOLD_MINUTES', 15),
        'rate_limit_per_minute' => (int) env('INVENTORY_API_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Báo lỗi
    |--------------------------------------------------------------------------
    |
    | Báo lỗi Chờ xác minh quá `backlog_hours` giờ kể từ lúc tạo thì vào danh sách tồn đọng.
    |
    */

    'defect' => [
        'backlog_hours' => (int) env('INVENTORY_DEFECT_BACKLOG_HOURS', 24),
    ],

];
