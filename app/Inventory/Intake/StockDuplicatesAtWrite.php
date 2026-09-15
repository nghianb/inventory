<?php

namespace App\Inventory\Intake;

use RuntimeException;

/**
 * Nội bộ BatchIntake: lúc ghi thật phát hiện dòng trùng trong kho mà nhân viên chưa tick xác
 * nhận bỏ qua. Dùng để rollback transaction ghi; ra ngoài module thành InvalidBatch.
 *
 * @internal
 */
final class StockDuplicatesAtWrite extends RuntimeException {}
