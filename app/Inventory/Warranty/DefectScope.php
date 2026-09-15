<?php

namespace App\Inventory\Warranty;

/**
 * Phạm vi lỗi của Báo lỗi đã Xác nhận: cả Đơn vị hàng (chuyển Lỗi) hoặc chỉ Slot (Đơn vị hàng vẫn
 * Hoạt động).
 */
enum DefectScope: string
{
    case Unit = 'unit';
    case Slot = 'slot';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Cả Đơn vị hàng',
            self::Slot => 'Chỉ Slot',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $scope): array => [$scope->value => $scope->label()])->all();
    }
}
