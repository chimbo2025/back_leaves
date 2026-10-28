<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;

class LeaveEligibilityService
{
    public function __construct(protected PolicyService $policy, protected LeavePolicyResolver $resolver) {}

    public function check(Employee $employee, string $leaveTypeId, string $startDate, ?string $endDate = null, array $options = []): array
    {
        $start = Carbon::parse($startDate);
        $end = $endDate ? Carbon::parse($endDate) : $start;
        if ($end->lt($start)) {
            $end = $start;
        }
        $days = $end->diffInDays($start) + 1;
        $resolved = $this->resolver->resolveWithRules($leaveTypeId, $startDate);
        $policy = $resolved['policy'] ?? ($this->resolver->resolve($leaveTypeId, $startDate) ?? $this->policy->policyEffectiveAt($leaveTypeId, $startDate));
        $blockers = [];
        $required = [];
        if (! $policy) {
            $blockers[] = 'No applicable policy (approved/active) found for this leave type and date.';

            return [
                'ok' => false,
                'blockers' => $blockers,
                'required_documents' => [],
                'policy_id' => null,
                'policy_version' => null,
            ];
        }
        // employee status
        if (isset($employee->is_active) && $employee->is_active === false) {
            $blockers[] = 'Employee is not active.';
        }
        // max per request
        if ($days > 0 && (float) $policy->max_days_per_request > 0 && $days > (float) $policy->max_days_per_request) {
            $blockers[] = 'Exceeds maximum days per request.';
        }
        // tenure
        if ($this->policy->tenureMonths($employee->hire_date) < (int) $policy->min_tenure_months) {
            $blockers[] = 'Minimum tenure not met.';
        }
        // notice
        $notice = (int) $policy->min_notice_days;
        if ($notice > 0 && empty($options['is_emergency_exception'])) {
            $allowed = now()->startOfDay()->addDays($notice);
            if ($start->startOfDay()->lt($allowed)) {
                $blockers[] = 'Minimum notice period not met.';
            }
        }
        // balance check
        try {
            $bal = $this->policy->balanceFor($employee->id, $leaveTypeId);
            if (($policy->max_days_per_year ?? 0) > 0 && $days > $bal['remaining']) {
                $blockers[] = 'Insufficient leave balance.';
            }
        } catch (\Throwable $e) {
            // ignore balance check errors
        }
        // documents
        if ($policy->requires_documents) {
            $required[] = 'Supporting documents may be required.';
        }
        // probation if field exists
        if (isset($employee->probation) && $employee->probation) {
            if (! empty($options['ignore_probation'])) {
                // ok
            } elseif ((int) $policy->min_tenure_months > 0 && $this->policy->tenureMonths($employee->hire_date) < (int) $policy->min_tenure_months) {
                $blockers[] = 'Probation period not completed.';
            }
        }
        // overlap — null-safe on both ends of the interval. An open-ended
        // request (return date set at approval) runs to the horizon, so a
        // null end_date must never reach whereBetween/'>=' as NULL: that used
        // to throw "Illegal operator and value combination" and 500 every
        // start-date-only submit. Existing open-ended rows are likewise
        // treated as running to the horizon instead of silently missing.
        $overlapQuery = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['submitted', 'under_review', 'approved']);
        if ($endDate === null) {
            // [start, ∞): blocked by anything still running from the start
            // date on, including requests that are themselves open-ended.
            $overlapQuery->where(function ($q) use ($startDate) {
                $q->where('end_date', '>=', $startDate)->orWhereNull('end_date');
            });
        } else {
            // [start, end] ∩ [existing.start, existing.end] ≠ ∅.
            $overlapQuery->where('start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->where('end_date', '>=', $startDate)->orWhereNull('end_date');
                });
        }
        $overlap = $overlapQuery->exists();
        if ($overlap) {
            $blockers[] = 'Overlapping leave exists.';
        }
        // medical required if policy requires docs
        $medicalRequired = (bool) $policy->requires_documents;

        return [
            'ok' => empty($blockers),
            'blockers' => $blockers,
            'required_documents' => array_values(array_unique($required)),
            'policy_id' => $policy->id,
            'policy_version' => $policy->version,
            'medical_required' => $medicalRequired,
        ];
    }
}
