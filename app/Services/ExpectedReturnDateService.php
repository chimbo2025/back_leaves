<?php

namespace App\Services;

use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Expected return date calculation (Phase 3).
 *
 * The leave_policies table has no return-buffer column, so the expected return
 * date is the first working day after the approved leave ends: the day after
 * `end_date`, advanced past weekends and any configured public holidays.
 *
 * The computation is designed to be frozen: ReturnToWorkService stores both the
 * resulting date and this snapshot on the leave_returns row when the request is
 * approved, so a later policy or holiday change can never silently move an
 * already-agreed return date.
 */
class ExpectedReturnDateService
{
    public const METHOD = 'next_working_day';

    /**
     * @return array{expected_return_date:string, snapshot:array<string,mixed>}|null
     */
    public function compute(LeaveRequest $request): ?array
    {
        if (! $request->end_date) {
            return null;
        }

        $leaveEnd = Carbon::parse($request->end_date->toDateString());
        $holidays = $this->publicHolidayDates();

        $expected = $leaveEnd->copy()->addDay();
        $guard = 0;

        while (($expected->isWeekend() || in_array($expected->toDateString(), $holidays, true)) && $guard < 366) {
            $expected->addDay();
            $guard++;
        }

        return [
            'expected_return_date' => $expected->toDateString(),
            'snapshot' => [
                'policy_id' => $request->policy_id,
                'policy_version' => $request->policy_version,
                'method' => self::METHOD,
                'leave_end_date' => $leaveEnd->toDateString(),
                'public_holidays_considered' => $holidays,
                'computed_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function expectedReturnDate(LeaveRequest $request): ?string
    {
        return $this->compute($request)['expected_return_date'] ?? null;
    }

    /**
     * @return array<int,string>
     */
    protected function publicHolidayDates(): array
    {
        $dates = [];

        foreach (config('leave.public_holidays', []) as $holiday) {
            $start = Carbon::parse(is_array($holiday) ? ($holiday['date'] ?? null) : $holiday);
            $end = is_array($holiday) && ! empty($holiday['end_date'])
                ? Carbon::parse($holiday['end_date'])
                : $start->copy();

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dates[] = $d->toDateString();
            }
        }

        return array_values(array_unique($dates));
    }
}
