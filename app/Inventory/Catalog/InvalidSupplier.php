<?php

namespace App\Inventory\Catalog;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: thông tin Nhà cung cấp không hợp lệ (tên trống hoặc đã có).
 */
class InvalidSupplier extends RuntimeException {}
