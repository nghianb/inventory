<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Chỉ tồn tại để đặt nhãn tiếng Việt: Dashboard của Filament không có chỗ nào khác để đổi nhãn,
 * mà đây là mục duy nhất trong menu còn mang chữ tiếng Anh.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?string $title = 'Tổng quan';
}
