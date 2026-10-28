<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePlan;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * LeaveDateProposalService (Phase 2 - Almanac leave planning).
 *
 * Generates up to three ranked date options for a leave plan using the
 * resolved active policy, eligibility checks, available balance, notice,
 * existing approved leave, public holidays, blackout periods, department/zone
 * staffing rules and (where supplied) employee preferences.
 *
 * Every option carries score_breakdown_json explaining the score. Invalid dates
 * are never proposed; when fewer than three valid options exist the valid
 * options are returned with an explanation, and when none exist no dates are
 * invented.
 *
 * validateWindow() is the single source of truth reused at selection/convert
 * time so a proposal cannot be selected after the facts on the ground change.
 */
class LeaveDateProposalService
{
    public const MAX_OPTIONS = 3;

    public function __construct(
        protected LeavePolicyResolver $resolver,
        protected LeaveEligibilityService $eligibility,
        protected PolicyService $policyService,
    ) {}

    /**
     * @return array{options: array<int,array<string,mixed>>, generated: int, explanation: ?string}
     */
    public function propose(LeavePlan $plan, ?int $desiredDays = null): array
    {
        $employee = $plan->employee;
        $leaveTypeId = $plan->leave_type_id;
        $policy = $plan->leave_policy_id
            ? LeavePolicy::find($plan->leave_policy_id)
            : $this->resolver->resolve($leaveTypeId, optional($plan->cycle_start)->toDateString());

        if (! $policy) {
            return [
                'options' => [],
                'generated' => 0,
                'explanation' => 'No applicable approved/active policy exists for this leave type; no valid dates can be proposed.',
            ];
        }

        $cycleStart = $plan->cycle_start ? Carbon::parse($plan->cycle_start) : Carbon::today();
        $cycleEnd = $plan->cycle_end ? Carbon::parse($plan->cycle_end) : $cycleStart->copy()->addYear();

        $maxPerRequest = (float) ($policy->max_days_per_request ?? 0);
        $days = $desiredDays ?: (int) max(1, $maxPerRequest);
        $notice = (int) ($policy->min_notice_days ?? 0);
        $earliestStart = Carbon::today()->addDays($notice);

        if ($earliestStart->lt($cycleStart)) {
            $earliestStart = $cycleStart->copy();
        }

        $existing = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['submitted', 'under_review', 'approved'])
            ->get(['start_date', 'end_date']);

        $holidays = $this->publicHolidays();
        $blackouts = $this->blackoutPeriods($leaveTypeId);

        $options = [];
        $skipped = [];
        $cursor = $earliestStart->copy();
        $guard = 0;

        while ($cursor->lte($cycleEnd) && count($options) < self::MAX_OPTIONS && $guard < 400) {
            $guard++;
            $end = $cursor->copy()->addDays(max(0, $days - 1));

            if ($end->gt($cycleEnd)) {
                $cursor->addDays(7);

                continue;
            }

            $candidate = $this->evaluateCandidate($employee, $leaveTypeId, $cursor->copy(), $end->copy(), $policy, $existing, $holidays, $blackouts);

            if ($candidate['valid']) {
                $options[] = $candidate;
            } else {
                $skipped[] = $candidate['reason'];
            }

            $cursor->addDays(7);
        }

        $explanation = null;
        if (count($options) < self::MAX_OPTIONS) {
            $uniqueReasons = array_values(array_unique(array_filter($skipped)));
            $explanation = count($options) === 0
                ? 'No valid date options were found within this cycle. '.implode(' ', array_slice($uniqueReasons, 0, 3))
                : 'Only '.count($options).' valid option(s) were found; the remaining candidates were excluded. '.implode(' ', array_slice($uniqueReasons, 0, 3));
        }

        usort($options, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'options' => $options,
            'generated' => count($options),
            'explanation' => $explanation,
        ];
    }

    /**
     * Re-validate a concrete window (used by select/convert). Returns the same
     * blocker vocabulary as LeaveEligibilityService plus blackout/staffing.
     *
     * @return array{ok: bool, blockers: array<int,string>, policy: LeavePolicy|null}
     */
    public function validateWindow(Employee $employee, string $leaveTypeId, Carbon $start, Carbon $end): array
    {
        $policy = $this->resolver->resolve($leaveTypeId, $start->toDateString());

        if (! $policy) {
            return ['ok' => false, 'blockers' => ['No applicable approved/active policy exists for this leave type and date.'], 'policy' => null];
        }

        $existing = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['submitted', 'under_review', 'approved'])
            ->get(['start_date', 'end_date']);

        $result = $this->evaluateCandidate(
            $employee,
            $leaveTypeId,
            $start->copy(),
            $end->copy(),
            $policy,
            $existing,
            $this->publicHolidays(),
            $this->blackoutPeriods($leaveTypeId),
        );

        return [
            'ok' => $result['valid'],
            'blockers' => $result['valid'] ? [] : [$result['reason']],
            'policy' => $policy,
        ];
    }

    /**
     * @param  Collection<int,LeaveRequest>  $existing
     * @param  array<int,array{start:Carbon,end:Carbon}>  $holidays
     * @param  array<int,array{start:Carbon,end:Carbon,label:string}>  $blackouts
     * @return array<string,mixed>
     */
    protected function evaluateCandidate(
        Employee $employee,
        string $leaveTypeId,
        Carbon $start,
        Carbon $end,
        LeavePolicy $policy,
        Collection $existing,
        array $holidays,
        array $blackouts,
    ): array {
        $breakdown = [];

        foreach ($blackouts as $blackout) {
            if ($start->lte($blackout['end']) && $end->gte($blackout['start'])) {
                return ['valid' => false, 'reason' => "Blackout period '{$blackout['label']}' excludes these dates."];
            }
        }

        foreach ($existing as $req) {
            if ($req->start_date && $req->end_date) {
                $rs = Carbon::parse($req->start_date);
                $re = Carbon::parse($req->end_date);
                if ($start->lte($re) && $end->gte($rs)) {
                    return ['valid' => false, 'reason' => 'Dates overlap existing leave already on file.'];
                }
            }
        }

        $elig = $this->eligibility->check(
            $employee,
            $leaveTypeId,
            $start->toDateString(),
            $end->toDateString(),
        );
        if (! $elig['ok']) {
            return ['valid' => false, 'reason' => implode(' ', array_slice($elig['blockers'], 0, 2))];
        }

        $balance = $this->policyService->balanceFor($employee->id, $leaveTypeId);
        $requestedDays = $start->diffInDays($end) + 1;
        if ((float) ($policy->max_days_per_year ?? 0) > 0 && $requestedDays > $balance['remaining']) {
            return ['valid' => false, 'reason' => 'Requested days exceed remaining balance.'];
        }
        $breakdown['balance_remaining'] = $balance['remaining'];
        $breakdown['requested_days'] = $requestedDays;

        $holidayOverlap = 0;
        foreach ($holidays as $holiday) {
            if ($start->lte($holiday['end']) && $end->gte($holiday['start'])) {
                $holidayOverlap++;
            }
        }
        $breakdown['holiday_overlap'] = $holidayOverlap;

        $staffing = $this->staffingImpact($employee, $start, $end);
        if (! $staffing['ok']) {
            return ['valid' => false, 'reason' => $staffing['reason']];
        }
        $breakdown['department_peers_on_leave'] = $staffing['peers_on_leave'];
        $breakdown['staff_remaining'] = $staffing['remaining'];

        $score = 100.0;
        $score -= $holidayOverlap * 10;
        $score -= $staffing['peers_on_leave'] * 15;
        $score += min(10.0, $balance['remaining'] / 2);
        $breakdown['score'] = round($score, 2);
        $breakdown['notes'] = [];

        if ($holidayOverlap > 0) {
            $breakdown['notes'][] = "{$holidayOverlap} public holiday(s) overlap this window.";
        }
        if ($staffing['peers_on_leave'] > 0) {
            $breakdown['notes'][] = "{$staffing['peers_on_leave']} colleague(s) in the same unit are already on leave.";
        }

        return [
            'valid' => true,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $requestedDays,
            'score' => round($score, 2),
            'score_breakdown_json' => $breakdown,
            'availability_status' => $staffing['peers_on_leave'] > 0 ? 'limited' : 'available',
            'reason' => null,
        ];
    }

    /**
     * @return array{ok: bool, reason: ?string, peers_on_leave: int, remaining: int}
     */
    protected function staffingImpact(Employee $employee, Carbon $start, Carbon $end): array
    {
        $rule = $this->staffingRule($employee);

        $peersQuery = LeaveRequest::whereIn('status', ['under_review', 'approved'])
            ->where('employee_id', '!=', $employee->id)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->whereHas('employee', function ($q) use ($employee, $rule) {
                if ($rule && ($rule['department_id'] ?? null)) {
                    $q->where('department_id', $rule['department_id']);
                } elseif ($rule && ($rule['zone_id'] ?? null)) {
                    $q->where('zone_id', $rule['zone_id']);
                } else {
                    $q->where('department_id', $employee->department_id);
                }
            });

        $peers = $peersQuery->count();

        $total = $this->unitHeadcount($employee, $rule);
        $remaining = max(0, $total - $peers - 1);
        $minimum = (int) ($rule['minimum_present'] ?? 0);

        if ($minimum > 0 && $remaining < $minimum) {
            return [
                'ok' => false,
                'reason' => "Staffing minimum not met ({$remaining} of {$total} would remain, minimum {$minimum}).",
                'peers_on_leave' => $peers,
                'remaining' => $remaining,
            ];
        }

        return ['ok' => true, 'reason' => null, 'peers_on_leave' => $peers, 'remaining' => $remaining];
    }

    /** @return array<string,mixed>|null */
    protected function staffingRule(Employee $employee): ?array
    {
        $rules = config('leave.staffing_minimums', []);

        foreach ($rules as $rule) {
            if (($rule['department_id'] ?? null) === $employee->department_id) {
                return $rule;
            }
        }

        foreach ($rules as $rule) {
            if (($rule['zone_id'] ?? null) === $employee->zone_id) {
                return $rule;
            }
        }

        return null;
    }

    /** @param array<string,mixed>|null $rule */
    protected function unitHeadcount(Employee $employee, ?array $rule): int
    {
        $count = Employee::where('is_active', true);

        if ($rule && ($rule['department_id'] ?? null)) {
            $count->where('department_id', $rule['department_id']);
        } elseif ($rule && ($rule['zone_id'] ?? null)) {
            $count->where('zone_id', $rule['zone_id']);
        } else {
            $count->where('department_id', $employee->department_id);
        }

        return max(1, $count->count());
    }

    /**
     * @return array<int,array{start:Carbon,end:Carbon}>
     */
    protected function publicHolidays(): array
    {
        return collect(config('leave.public_holidays', []))->map(function ($h) {
            $start = Carbon::parse($h['date'] ?? $h);
            $end = isset($h['end_date']) && $h['end_date'] ? Carbon::parse($h['end_date']) : $start->copy();

            return ['start' => $start, 'end' => $end];
        })->all();
    }

    /**
     * @return array<int,array{start:Carbon,end:Carbon,label:string}>
     */
    protected function blackoutPeriods(string $leaveTypeId): array
    {
        return collect(config('leave.blackout_periods', []))
            ->filter(fn ($b) => empty($b['leave_type_id']) || $b['leave_type_id'] === $leaveTypeId)
            ->map(fn ($b) => [
                'start' => Carbon::parse($b['start_date']),
                'end' => Carbon::parse($b['end_date']),
                'label' => $b['label'] ?? 'Blackout',
            ])
            ->all();
    }
}
