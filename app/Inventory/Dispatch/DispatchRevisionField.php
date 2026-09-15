<?php

namespace App\Inventory\Dispatch;

/**
 * Trường sửa được của Phiếu xuất Hoàn tất. Kênh bán, Dòng xuất và Slot không sửa được.
 */
enum DispatchRevisionField: string
{
    case ExternalRef = 'external-ref';
    case Customer = 'customer';
    case Note = 'note';
    case SalePrice = 'sale-price';

    public function label(): string
    {
        return match ($this) {
            self::ExternalRef => 'Mã đơn ngoài',
            self::Customer => 'Khách',
            self::Note => 'Ghi chú',
            self::SalePrice => 'Giá bán',
        };
    }
}
