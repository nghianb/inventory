<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lô nhập chưa xác nhận quá 24 giờ: quá hạn xác nhận, xoá nội dung tạm và file upload tạm.
Schedule::command('inventory:intake:purge')->hourly();

// Ảnh Báo lỗi không còn Báo lỗi nào trỏ tới (tiến trình dừng giữa lúc lưu ảnh và commit).
Schedule::command('inventory:defect-reports:purge')->hourly();
