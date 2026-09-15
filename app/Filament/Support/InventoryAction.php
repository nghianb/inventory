<?php

namespace App\Filament\Support;

use App\Inventory\Access\MissingRole;
use App\Inventory\Catalog\InvalidProductConfiguration;
use App\Inventory\Catalog\InvalidSupplier;
use App\Inventory\Catalog\ProductHasStock;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use RuntimeException;

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
        } catch (InvalidProductConfiguration|InvalidSupplier|MissingRole|ProductHasStock $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $action->halt();

            throw new RuntimeException('Action đã dừng.', previous: $exception);
        }
    }
}
