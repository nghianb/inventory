<?php

use App\Inventory\Encryption\KeyFingerprintMismatch;
use App\Models\SecurityLogEntry;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;

it('đăng ký dấu vân tay các khoá từ server rồi kiểm tra thành công', function () {
    $this->artisan('inventory:keys:register')
        ->expectsOutputToContain('khoá mã hoá nội dung phiên bản 2')
        ->assertSuccessful();

    $this->artisan('inventory:keys:verify')->assertSuccessful();

    expect(SecurityLogEntry::count())->toBe(4);
});

it('lệnh kiểm tra thất bại và nêu rõ khoá nào sai', function () {
    $this->artisan('inventory:keys:register')->assertSuccessful();

    config(['inventory.keys.hmac' => '1:base64:+aDuV69xpMmq8HrVKo6jtB43YKS+Sd8UDwlDy4k5kgk=']);

    $this->artisan('inventory:keys:verify')
        ->expectsOutputToContain('khoá mã hoá HMAC phiên bản 1: dấu vân tay không khớp với DB')
        ->assertFailed();

    $this->artisan('inventory:keys:register')
        ->expectsOutputToContain('khoá mã hoá HMAC phiên bản 1')
        ->assertFailed();
});

it('queue worker từ chối khởi động khi dấu vân tay khoá không khớp', function () {
    expect(fn () => event(new WorkerStarting('database', 'default', new WorkerOptions)))
        ->toThrow(KeyFingerprintMismatch::class, 'chưa đăng ký');

    $this->artisan('inventory:keys:register')->assertSuccessful();

    event(new WorkerStarting('database', 'default', new WorkerOptions));
});

it('mỗi job từ chối chạy khi dấu vân tay khoá không khớp', function () {
    try {
        app(Dispatcher::class)->dispatch(CallQueuedClosure::create(fn () => Cache::put('job-ran', true)));
        $this->fail('Job chạy dù dấu vân tay khoá chưa đăng ký.');
    } catch (KeyFingerprintMismatch $exception) {
        expect($exception->getMessage())->toContain('chưa đăng ký')
            ->and(Cache::has('job-ran'))->toBeFalse();
    }

    $this->artisan('inventory:keys:register')->assertSuccessful();
    app(Dispatcher::class)->dispatch(CallQueuedClosure::create(fn () => Cache::put('job-ran', true)));

    expect(Cache::has('job-ran'))->toBeTrue();
});
