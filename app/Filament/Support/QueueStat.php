<?php

namespace App\Filament\Support;

use App\Filament\Resources\Dispatches\DispatchResource;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Một ô "còn bao nhiêu việc" trên trang Tổng quan. Ô bằng 0 **vẫn hiện**, chỉ chuyển xám: "0 Báo lỗi
 * Chờ xác minh" là một thông tin — nó nói đã kiểm rồi, sạch — còn ô biến mất thì không phân biệt được
 * với widget hỏng. Ẩn ô cũng làm bố cục co giãn theo dữ liệu, đúng thứ bố cục Tổng quan tránh.
 *
 * Mọi ô đều bấm được: một con số không dẫn đi đâu bắt nhân viên tự đi tìm đúng thứ nó lẽ ra tiết kiệm.
 */
final class QueueStat
{
    public static function make(string $label, int $count, string $url, string $description, string $color = 'warning'): Stat
    {
        return Stat::make($label, DispatchResource::count($count))
            ->description($count === 0 ? 'Không còn việc' : $description)
            ->color($count === 0 ? 'gray' : $color)
            ->url($url);
    }
}
