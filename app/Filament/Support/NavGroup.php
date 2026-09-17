<?php

namespace App\Filament\Support;

use Filament\Support\Contracts\HasLabel;

/**
 * Nhóm trong sidebar của panel. Thứ tự case là thứ tự nhóm hiện ra: nhóm được dùng dày nhất lên
 * trên, nhóm chỉ Quản trị thấy nằm đáy. AdminPanelProvider lấy cả nhãn lẫn thứ tự từ đây, nên
 * thêm nhóm mới là thêm một case và một nhánh match, không phải sửa AdminPanelProvider.
 *
 * Thứ tự các mục *trong* một nhóm nằm ở $navigationSort của từng Resource/Page.
 *
 * Nhập hàng và Xuất hàng là *công việc*, không phải Vai trò (xem CONTEXT.md).
 */
enum NavGroup implements HasLabel
{
    case XuatHang;
    case NhapHang;
    case KhoHang;
    case BaoCao;
    case NhatKy;
    case HeThong;

    public function getLabel(): string
    {
        return match ($this) {
            self::XuatHang => 'Xuất hàng',
            self::NhapHang => 'Nhập hàng',
            self::KhoHang => 'Kho hàng',
            self::BaoCao => 'Báo cáo',
            self::NhatKy => 'Nhật ký',
            self::HeThong => 'Hệ thống',
        };
    }
}
