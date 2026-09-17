<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Throwable;

/**
 * Bản cho trang của {@see InventoryAction::attempt()}: đổi lỗi nghiệp vụ thành thông báo,
 * cuộn ngược giao dịch và giữ nguyên form để nhân viên sửa.
 *
 * Phải là trait chứ không phải hàm tĩnh vì `halt()` là protected trên chính trang.
 */
trait HandlesBusinessErrors
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function attempt(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if (! InventoryAction::isBusinessError($exception)) {
                throw $exception;
            }

            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);

            throw $exception;
        }
    }

    abstract protected function halt(bool $shouldRollbackDatabaseTransaction = false): void;
}
