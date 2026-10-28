<?php

namespace App\Providers;

use App\Listeners\LeaveNotificationSubscriber;
use App\Support\LaravelNotificationMailer;
use App\Support\NotificationMailer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The queued email job depends on the contract, not the Mail facade, so
        // a test can swap in a mailer that fails without a real transport.
        $this->app->bind(
            NotificationMailer::class,
            LaravelNotificationMailer::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 4: turn every leave domain event into a notification.
        Event::subscribe(LeaveNotificationSubscriber::class);

        // MariaDB/MySQL < 5.7.7: composite string PKs (employee_roles) exceed
        // the 3072-byte InnoDB key limit at the default 255 chars.
        Schema::defaultStringLength(191);
    }
}
