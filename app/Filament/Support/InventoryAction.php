<?php

namespace App\Filament\Support;

use App\Inventory\Access\MissingRole;
use App\Inventory\Catalog\InvalidProductConfiguration;
use App\Inventory\Catalog\InvalidSupplier;
use App\Inventory\Catalog\ProductHasStock;
use App\Inventory\Dispatch\InvalidDispatch;
use App\Inventory\Dispatch\InvalidSalesChannel;
use App\Inventory\Dispatch\OutOfStock;
use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Inventory\Intake\InvalidBatch;
use App\Inventory\Reveal\InvalidReveal;
use App\Inventory\Stock\InvalidDefectMarking;
use App\Inventory\Stock\InvalidVoid;
use App\Inventory\Warranty\InvalidDefectReport;
use App\Inventory\Warranty\InvalidReplacement;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use RuntimeException;
use Throwable;

/**
 * Cầu nối mỏng giữa action của panel và module Kho: lấy nhân viên đang đăng nhập làm
 * tác nhân, đổi lỗi nghiệp vụ thành thông báo và giữ modal mở.
 */
final class InventoryAction
{
    public static function actor(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function attempt(Action $action, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if (! self::isBusinessError($exception)) {
                throw $exception;
            }

            Notification::make()->danger()->title($exception->getMessage())->send();
            $action->halt();

            throw new RuntimeException('Action đã dừng.', previous: $exception);
        }
    }

    /**
     * Lỗi nghiệp vụ có thông báo hiển thị được cho nhân viên.
     */
    public static function isBusinessError(Throwable $exception): bool
    {
        return $exception instanceof InvalidProductConfiguration
            || $exception instanceof InvalidSupplier
            || $exception instanceof MissingRole
            || $exception instanceof ProductHasStock
            || $exception instanceof InvalidBatch
            || $exception instanceof InvalidReveal
            || $exception instanceof InvalidSalesChannel
            || $exception instanceof InvalidDispatch
            || $exception instanceof OutOfStock
            || $exception instanceof InvalidVoid
            || $exception instanceof InvalidDefectMarking
            || $exception instanceof InvalidDefectReport
            || $exception instanceof InvalidReplacement
            || $exception instanceof KeyFingerprintMismatch;
    }
}
