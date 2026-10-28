<?php

namespace App\Jobs;

use App\Services\LeaveReminderScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4 — the queued entry point the scheduler runs.
 *
 * It does no work itself: it delegates to LeaveReminderScheduler so the sweeps
 * can be exercised directly in a test without the queue or the clock. Every
 * sweep is idempotent, so a duplicate run is harmless.
 */
class RunLeaveReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @return array<string,int>
     */
    public function handle(LeaveReminderScheduler $scheduler): array
    {
        return $scheduler->run();
    }
}
