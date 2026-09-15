<?php

namespace App\Inventory\Stock;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Đơn vị hàng không Đánh dấu Lỗi hay Khôi phục được (thiếu lý do, sai trạng thái).
 */
class InvalidDefectMarking extends RuntimeException {}
