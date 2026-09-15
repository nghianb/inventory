<?php

namespace App\Inventory\Stock;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Slot hoặc Đơn vị hàng không Huỷ hàng được ở trạng thái hiện tại.
 */
class InvalidVoid extends RuntimeException {}
