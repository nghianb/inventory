<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dòng xuất loại Đổi hàng và Giao thay không có Giá bán, nhưng Sửa phiếu từng ghi được Giá bán
     * vào dòng Giao thay. Tiền ấy vào tổng Giá bán của báo cáo Nhập/xuất trong khi Slot của dòng cố
     * ý không nằm ở phần Xuất, nên một kỳ có doanh thu không có Slot nào đối ứng. Chặn ghi mới nằm ở
     * DispatchEditor; đây dọn nốt phần đã lỡ ghi.
     */
    public function up(): void
    {
        DB::table('dispatch_lines')
            ->whereIn('kind', ['corrective', 'replacement'])
            ->whereNotNull('sale_price')
            ->update(['sale_price' => null]);
    }

    public function down(): void
    {
        // Không dựng lại: những Giá bán ấy chưa từng hợp lệ.
    }
};
