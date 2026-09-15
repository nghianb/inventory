<?php

use Illuminate\Support\Facades\DB;

it('bộ test chạy trên PostgreSQL thật', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::scalar('select version()'))->toStartWith('PostgreSQL');
});

it('dùng múi giờ nghiệp vụ Asia/Ho_Chi_Minh', function () {
    $this->travelTo('2026-09-15 00:30:00 UTC');

    expect(now()->timezoneName)->toBe('Asia/Ho_Chi_Minh')
        ->and(now()->format('Y-m-d H:i'))->toBe('2026-09-15 07:30');
});
