<?php

namespace App\Services;

use App\Events\LateReturnDetected;
use App\Events\LeaveBalanceReminderDue;
use App\Events\LeaveEndingSoon;
use App\Events\LeavePlanReadyForSelection;
use App\Events\LeaveStartingSoon;
use App\Events\ReturnReminderDue;
use App\Models\Employee;
use App\Models\LeavePlan;
use App\Models\LeaveRequest;
use App\Models\LeaveReturn;
use App\Models\LeaveType;
use App\Models\NotificationDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — the scheduled reminder sweeps.
 *
 * Each sweep finds rows that are due today and dispatches a domain event; the
 * subscriber turns that into notifications. Nothing here decides the lead time
 * itself: every threshold is read from config('leave.notifications.*'), so a
 * deployment can move a reminder without a code change and no controller
 * hard-codes a day count.
 *
 * Every sweep is idempotent. The event handlers derive an idempotency key from
 * the reference (request/return/plan id) and the reference *date* — the leave
 * start date, the expected return date, and so on — not from "today". So
 * running the sweep twice in one day, or every day through the whole lead
 * window, delivers at most once per reference.
 */
class LeaveReminderScheduler
{
    public function __construct(
        protected PolicyService $policy,
    ) {}

    /**
     * @return array<string,int>
     */
    public function run(): array
    {
        return [
            'leave_cycle' => $this->leaveCycleReminders(),
            'leave_start' => $this->leaveStartReminders(),
            'leave_end' => $this->leaveEndReminders(),
            'return_reminder' => $this->returnReminders(),
            'late_return' => $this->detectLateReturns(),
            'low_balance' => $this->balanceReminders(),
            'unselected_plan' => $this->unselectedPlanReminders(),
        ];
    }

    public function leaveStartReminders(): int
    {
        $target = now()->addDays($this->lead('leave_start_days_before'))->toDateString();

        $requests = DB::table('leave_requests')
            ->where('status', 'approved')
            ->whereDate('start_date', $target)
            ->pluck('id');

        $count = 0;
        foreach ($requests as $id) {
            $request = LeaveRequest::with('leaveType')->find($id);
            if ($request) {
                event(new LeaveStartingSoon($request));
                $count++;
            }
        }

        return $count;
    }

    public function leaveEndReminders(): int
    {
        $target = now()->addDays($this->lead('leave_end_days_before'))->toDateString();

        $requests = DB::table('leave_requests')
            ->where('status', 'approved')
            ->whereDate('end_date', $target)
            ->pluck('id');

        $count = 0;
        foreach ($requests as $id) {
            $request = LeaveRequest::with('leaveType')->find($id);
            if ($request) {
                event(new LeaveEndingSoon($request));
                $count++;
            }
        }

        return $count;
    }

    public function returnReminders(): int
    {
        $target = now()->addDays($this->lead('return_reminder_days_before'))->toDateString();

        $returns = LeaveReturn::where('status', LeaveReturn::STATUS_PENDING)
            ->whereDate('expected_return_date', $target)
            ->get();

        foreach ($returns as $return) {
            event(new ReturnReminderDue($return));
        }

        return $returns->count();
    }

    public function detectLateReturns(): int
    {
        $cutoff = now()->subDays($this->lead('late_return_grace_days'))->toDateString();

        $returns = LeaveReturn::where('status', LeaveReturn::STATUS_PENDING)
            ->whereDate('expected_return_date', '<', $cutoff)
            ->get();

        foreach ($returns as $return) {
            event(new LateReturnDetected($return));
        }

        return $returns->count();
    }

    public function balanceReminders(): int
    {
        $threshold = (float) config('leave.notifications.low_balance_threshold', 3.0);
        $count = 0;

        foreach (Employee::where('is_active', true)->get() as $employee) {
            foreach (LeaveType::all() as $leaveType) {
                $balance = $this->policy->balanceFor($employee->id, $leaveType->id);
                $remaining = (float) ($balance['remaining'] ?? 0);
                $accrued = (float) ($balance['accrued'] ?? 0);

                if ($accrued <= 0 || $remaining <= 0 || $remaining > $threshold) {
                    continue;
                }

                $balance['leave_type_name'] = $leaveType->name;
                $balance['context'] = 'low_balance';
                event(new LeaveBalanceReminderDue($employee, $leaveType->id, $balance));
                $count++;
            }
        }

        return $count;
    }

    /**
     * A nudge when an employee's leave cycle is about to end — the point at
     * which unused carry-over may expire. Fires once per cycle end date.
     */
    public function leaveCycleReminders(): int
    {
        $lead = $this->lead('plan_selection_lead_days');
        $count = 0;

        foreach (Employee::where('is_active', true)->get() as $employee) {
            if (! $employee->hire_date) {
                continue;
            }

            $cycleEnd = $this->policy->serviceYearStart((string) $employee->hire_date)->addYear()->subDay();

            if ($cycleEnd->diffInDays(now(), false) < 0 || $cycleEnd->diffInDays(now()) > $lead) {
                continue;
            }

            $balance = ['remaining' => null, 'accrued' => null, 'context' => 'cycle_end', 'cycle_end' => $cycleEnd->toDateString(), 'leave_type_name' => 'leave'];
            event(new LeaveBalanceReminderDue($employee, 'cycle', $balance));
            $count++;
        }

        return $count;
    }

    public function unselectedPlanReminders(): int
    {
        $lead = $this->lead('plan_selection_lead_days');
        $count = 0;

        $plans = LeavePlan::whereIn('status', ['proposed', 'employee_selected'])
            ->whereNotNull('cycle_end')
            ->get();

        foreach ($plans as $plan) {
            $cycleEnd = Carbon::parse($plan->cycle_end);
            if ($cycleEnd->diffInDays(now(), false) < 0 || $cycleEnd->diffInDays(now()) > $lead) {
                continue;
            }

            event(new LeavePlanReadyForSelection($plan));
            $count++;
        }

        return $count;
    }

    /**
     * Number of already-recorded deliveries for a logical notification. Useful
     * for diagnostics and asserted by the idempotency test.
     */
    public function deliveriesFor(string $idempotencyPrefix): int
    {
        return NotificationDelivery::where('idempotency_key', 'like', $idempotencyPrefix.':%')->count();
    }

    protected function lead(string $key): int
    {
        return (int) config("leave.notifications.{$key}", 0);
    }
}
