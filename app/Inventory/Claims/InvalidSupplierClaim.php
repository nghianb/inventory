<?php

namespace App\Inventory\Claims;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Khiếu nại nhà cung cấp không tạo, sửa, gửi, giải quyết hay huỷ được.
 */
class InvalidSupplierClaim extends RuntimeException {}
