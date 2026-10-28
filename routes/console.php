<?php

use App\Jobs\RunLeaveReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Phase 4 — leave notification schedule
|--------------------------------------------------------------------------
|
| One daily sweep runs every reminder (leave-cycle, start, end, return,
| late-return, low-balance, unselected-plan). Each sweep is idempotent, so a
| re-run is harmless, and withoutOverlapping stops a slow mail run from
| stacking. Lead times live in config/leave.php under `notifications`.
|
*/
Schedule::job(new RunLeaveReminders)
    ->dailyAt('07:00')
    ->name('leave-reminders')
    ->withoutOverlapping();
