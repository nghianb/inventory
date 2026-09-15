<?php

namespace App\Inventory\Catalog;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: cấu hình Sản phẩm không hợp lệ (thiếu Khoá chống trùng, regex sai,
 * Mã sản phẩm đã dùng...). Thông báo viết cho Quản trị đọc.
 */
class InvalidProductConfiguration extends RuntimeException {}
