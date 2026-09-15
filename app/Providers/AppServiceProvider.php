<?php

namespace App\Providers;

use App\Inventory\Encryption\KeyFingerprints;
use App\Listeners\RecordAuthenticationEvents;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(RecordAuthenticationEvents::class);

        // Job nhập và xuất: khoá sai thì worker từ chối khởi động, và mỗi job từ chối chạy
        // (kể cả `queue:work --once`, driver sync, hay .env đổi khi worker đang chạy).
        Event::listen([WorkerStarting::class, JobProcessing::class], fn () => $this->app->make(KeyFingerprints::class)->verify());
    }
}
