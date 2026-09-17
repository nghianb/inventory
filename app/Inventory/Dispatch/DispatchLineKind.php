<?php

namespace App\Inventory\Dispatch;

use App\Inventory\Reports\MovementReport;
use App\Inventory\Reports\SoldLines;

/**
 * Loại Dòng xuất. Giao thay chỉ thêm dòng khi giao sang Sản phẩm khác; mỗi Đổi hàng thêm một dòng.
 * Cả hai loại ấy đều không có Giá bán: xem {@see allowsSalePrice()}.
 */
enum DispatchLineKind: string
{
    case Sale = 'sale';
    case Additional = 'additional';
    case Corrective = 'corrective';
    case Replacement = 'replacement';
    case Recorded = 'recorded';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Giao bán',
            self::Additional => 'Giao thêm',
            self::Corrective => 'Giao thay',
            self::Replacement => 'Đổi hàng',
            self::Recorded => 'Ghi nhận giao bù',
        };
    }

    /**
     * Loại này có được ghi Giá bán không. Đổi hàng và Giao thay thì không: Chi phí đổi hàng trừ vào
     * Lãi ròng kho chứ không vào Lãi gộp của Phiếu xuất gốc, còn Slot Giao thay đã quy về Dòng xuất
     * gốc — doanh thu ghi ở đây sẽ không có Slot nào tương ứng trong phần Xuất của báo cáo. Ghi nhận
     * giao bù thì có: nó chép lại một lần bán đã thực sự xảy ra và đã thu tiền.
     */
    public function allowsSalePrice(): bool
    {
        return in_array($this, self::salePriceKinds(), true);
    }

    /**
     * Các loại Dòng xuất ứng với tiền khách trả. Một danh sách duy nhất, dùng chung cho
     * {@see allowsSalePrice()}, báo cáo Lãi/lỗ ({@see SoldLines}) và Nhập/xuất
     * ({@see MovementReport}): thêm một loại nữa chỉ phải sửa ở đây.
     *
     * Ràng buộc `dispatch_lines_sale_price_kind` ở tầng DB cố tình chép cứng danh sách này thay vì
     * đọc từ đây: migration là bản ghi lịch sử, phải chạy ra đúng cùng một kết quả về sau.
     *
     * @return list<self>
     */
    public static function salePriceKinds(): array
    {
        return [self::Sale, self::Additional, self::Recorded];
    }

    /**
     * @return list<string> giá trị cột `kind` của {@see salePriceKinds()}
     */
    public static function salePriceValues(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::salePriceKinds());
    }
}
