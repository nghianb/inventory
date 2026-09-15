<?php

namespace App\Inventory\Intake;

use App\Models\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pha 1 của nhập hàng: phân loại từng dòng để xem trước. Payload chỉ mang định danh Lô
 * nhập, không mang nội dung. Worker kiểm tra dấu vân tay khoá trước mỗi job.
 */
class ValidateBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Batch $batch) {}

    public function handle(BatchIntake $intake): void
    {
        $intake->validate($this->batch);
    }

    public function failed(?Throwable $exception): void
    {
        app(BatchIntake::class)->markValidationFailed($this->batch, $exception);
    }
}
