<?php

namespace App\Inventory\Intake;

use RuntimeException;

/**
 * Lỗi nghiệp vụ: Lô nhập gửi đi không hợp lệ hoặc không ở trạng thái cho phép thao tác.
 */
class InvalidBatch extends RuntimeException {}
