<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lô nhập chưa xác nhận quá 24 giờ: quá hạn xác nhận, xoá nội dung tạm và file upload tạm.
Schedule::command('inventory:intake:purge')->hourly();
