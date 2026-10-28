<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\PolicyRules;
use Illuminate\Support\Collection;

/**
 * Employee × leave-type pair analysis for payment visibility reporting.
 *
 * Several required categories cannot be answered from payment cases alone:
 * "eligible without payment request" and "eligible but no leave taken"
 * describe the ABSENCE of a case or a request. This service computes, for
 * every in-scope active employee and every payable leave type, whether an
 * approved leave exists, whether a case exists, and whether the pair is
 * eligible — and buckets the pairs into the report's overlapping sets.
 *
 * What "eligible" means here (documented deliberately): a POLICY-LEVEL
 * screen only — employee active, type supported, a policy resolves,
 * payments enabled by policy, unpaid/statutory type rules satisfied, and
 * (when a request is linked) that request approved. Case-time inputs that
 * only exist when a case is created (days for hypothetical pairs, salary
 * basis, loan flag, duplicate windows) are NOT re-evaluated here; the
 * authoritative per-case verdict is always the case's stored
 * eligibility_snapshot, which this service prefers whenever a case exists.
 * In other words: pairs WITH a case use the frozen case-time verdict;
 * pairs WITHOUT one use this screen. Both halves are reported together so
 * HR can see who could be paid next.
 *
 * Memory discipline: the analysis works over id-keyed narrow maps (ids,
 * statuses, amounts, dates) preloaded in a handful of queries — full rows
 * are only ever loaded for the page being rendered. Detail endpoints slice
 * member keys first and load display rows for the slice only.
 */
class EmployeeLeavePaymentReportService
{
    public function __construct(
        protected LeavePolicyResolver $resolver,
        protected AccessScopeService $accessScope,
    ) {}

    /**
     * Leave types payable in principle (encashment screen).
     *
     * @return Collection<int,LeaveType>
     */
    public function payableTypes(?string $paymentType = null): Collection
    {
        $paymentType ??= LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT;

        return LeaveType::query()->get()->filter(
            fn (LeaveType $type) => $this->typeScreen($type, $paymentType)['eligible']
        )->values();
    }

    /**
     * Policy-level screen for one type (see class docblock for scope).
     *
     * @return array{eligible:bool,policy_id:?string}
     */
    public function typeScreen(LeaveType $type, string $paymentType): array
    {
        $resolved = $this->resolver->resolveWithRules($type->id);
        $policy = $resolved['policy'];
        $rules = $resolved['resolved_rules'] ?? [];

        if ($policy === null) {
            return ['eligible' => false, 'policy_id' => null];
        }

        if (! PolicyRules::enabled($rules, LeavePaymentEligibilityService::RULE_PAYMENT_ENABLED, true)) {
            return ['eligible' => false, 'policy_id' => $policy->id];
        }

        if ($paymentType === LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT) {
            $allowUnpaid = PolicyRules::enabled($rules, LeavePaymentEligibilityService::RULE_ALLOW_UNPAID, false);
            if (! ((bool) $type->is_paid_default) && ! $allowUnpaid) {
                return ['eligible' => false, 'policy_id' => $policy->id];
            }

            $statutory = in_array($type->code, ['MATERNITY', 'PATERNITY'], true);
            $allowStatutory = PolicyRules::enabled($rules, LeavePaymentEligibilityService::RULE_ALLOW_STATUTORY, false);
            if ($statutory && ! $allowStatutory) {
                return ['eligible' => false, 'policy_id' => $policy->id];
            }
        }

        return ['eligible' => true, 'policy_id' => $policy->id];
    }

    /**
     * Full pair analysis for the caller's scope and filters.
     *
     * Buckets are overlapping sets (one pair can sit in several): each card
     * counts its own bucket independently.
     *
     * @param  array<int,string>|null  $caseStatuses  When set, only pairs whose
     *                                                latest case carries one of these statuses are bucketed at all. Used
     *                                                for the Accounting audience, which may only ever see settlement
     *                                                statuses no matter which category it opens.
     * @return array{
     *   pairs: array<int,array{employee_id:string,leave_type_id:string,request_id:?int,case_id:?int,eligible:bool,eligible_source:string}>,
     *   buckets: array<string,array<int,array{employee_id:string,leave_type_id:string}>>,
     *   counts: array<string,int>
     * }
     */
    public function analyze(User $user, LeaveReportFilters $filters, string $paymentType, ?array $caseStatuses = null): array
    {
        $employees = $this->scopedEmployees($user, $filters);
        $types = $this->payableTypes($paymentType);

        if ($employees->isEmpty() || $types->isEmpty()) {
            return $this->emptyAnalysis();
        }

        $employeeIds = $employees->pluck('id')->all();
        $typeIds = $types->pluck('id')->all();

        // Narrow maps: ids and flags only, two queries total.
        $approvedQuery = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('leave_type_id', $typeIds)
            ->select(['id', 'employee_id', 'leave_type_id', 'ref', 'status', 'start_date', 'end_date', 'days']);
        $this->applyRequestWindow($approvedQuery, $filters);
        /** @var array<string,array<string,array<string,mixed>>> $approvedMap[employee][type] */
        $approvedMap = [];
        foreach ($approvedQuery->get() as $req) {
            $approvedMap[$req->employee_id][$req->leave_type_id] = [
                'id' => $req->id,
                'ref' => $req->ref,
                'status' => $req->status,
                'start_date' => $req->start_date?->toDateString(),
                'end_date' => $req->end_date?->toDateString(),
                'days' => $req->days !== null ? (float) $req->days : null,
                'arrived' => $req->start_date !== null && $req->start_date->toDateString() <= now()->toDateString(),
            ];
        }

        $caseQuery = LeavePaymentCase::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('leave_type_id', $typeIds)
            ->where('payment_type', $paymentType)
            ->select(['id', 'employee_id', 'leave_type_id', 'status', 'eligible_amount', 'calculated_amount', 'created_at']);
        $this->applyCaseWindow($caseQuery, $filters);
        /** @var array<string,array<string,array<string,mixed>>> $caseMap[employee][type] latest by id */
        $caseMap = [];
        foreach ($caseQuery->orderBy('id')->get() as $case) {
            $caseMap[$case->employee_id][$case->leave_type_id] = [
                'id' => $case->id,
                'status' => $case->status,
                'eligible_amount' => $case->eligible_amount !== null ? (float) $case->eligible_amount : null,
                'calculated_amount' => $case->calculated_amount !== null ? (float) $case->calculated_amount : null,
            ];
        }

        // Stored case-time verdicts for pairs that have a case.
        $caseIds = [];
        foreach ($caseMap as $byType) {
            foreach ($byType as $row) {
                $caseIds[] = $row['id'];
            }
        }
        $storedOk = $caseIds === []
            ? []
            : LeavePaymentCase::query()->whereKey($caseIds)->pluck('eligibility_snapshot', 'id')
                ->map(fn ($snap) => is_array($snap) && ($snap['blockers'] ?? null) === [])
                ->all();

        $pairs = [];
        $buckets = [
            'without_request' => [],
            'eligible_no_leave' => [],
            'leave_taken_unpaid' => [],
            'eligible_unpaid' => [],
        ];

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $req = $approvedMap[$employee->id][$type->id] ?? null;
                $case = $caseMap[$employee->id][$type->id] ?? null;

                if ($case !== null) {
                    $eligible = (bool) ($storedOk[$case['id']] ?? false);
                    $source = 'case_snapshot';
                } else {
                    $eligible = $this->screenPair($employee, $type, $req);
                    $source = 'policy_screen';
                }

                if ($caseStatuses !== null && ($case === null || ! in_array($case['status'], $caseStatuses, true))) {
                    continue;
                }

                $key = ['employee_id' => $employee->id, 'leave_type_id' => $type->id];
                $pairs[] = $key + [
                    'request_id' => $req['id'] ?? null,
                    'case_id' => $case['id'] ?? null,
                    'eligible' => $eligible,
                    'eligible_source' => $source,
                ];

                if (! $eligible) {
                    continue;
                }

                if ($case === null) {
                    $buckets['without_request'][] = $key;
                }

                if ($req === null) {
                    $buckets['eligible_no_leave'][] = $key;
                }

                if ($req !== null && ($req['arrived'] ?? false) && ($case === null || $case['status'] !== LeavePaymentCase::STATUS_PAID)) {
                    $buckets['leave_taken_unpaid'][] = $key;
                }

                if ($case === null || $case['status'] !== LeavePaymentCase::STATUS_PAID) {
                    $buckets['eligible_unpaid'][] = $key;
                }
            }
        }

        return [
            'pairs' => $pairs,
            'buckets' => $buckets,
            'counts' => [
                'eligible' => count(array_filter($pairs, fn ($p) => $p['eligible'])),
                'without_request' => count($buckets['without_request']),
                'eligible_no_leave' => count($buckets['eligible_no_leave']),
                'leave_taken_unpaid' => count($buckets['leave_taken_unpaid']),
                'eligible_unpaid' => count($buckets['eligible_unpaid']),
            ],
        ];
    }

    /**
     * Policy-level screen for a caseless pair: active employee (guaranteed by
     * the employee query), payable type (guaranteed by payableTypes), plus
     * the linked-request-approved rule when a request exists.
     */
    protected function screenPair(Employee $employee, LeaveType $type, ?array $request): bool
    {
        if (! $employee->is_active) {
            return false;
        }

        if ($request !== null && $request['status'] !== 'approved') {
            return false;
        }

        return true;
    }

    /**
     * Active employees in the caller's leave_payments scope, narrowed by the
     * identity filters. Narrow selects only — display rows load per page.
     *
     * @return Collection<int,Employee>
     */
    protected function scopedEmployees(User $user, LeaveReportFilters $filters): Collection
    {
        // is_active is selected (not just filtered) because screenPair reads
        // it off the model; selecting id/full_name alone made every pair
        // fail the screen with a null flag.
        $query = Employee::query()->where('is_active', true)->select(['id', 'full_name', 'is_active']);

        if (! empty($filters->get('employee_id'))) {
            $query->where('id', $filters->get('employee_id'));
        }

        if (! empty($filters->get('department_id'))) {
            $query->where('department_id', $filters->get('department_id'));
        }

        if (! empty($filters->get('zone_id'))) {
            $query->where('zone_id', $filters->get('zone_id'));
        }

        if (! empty($filters->get('search'))) {
            $search = '%'.str_replace(['%', '_'], '', (string) $filters->get('search')).'%';
            $query->where('full_name', 'like', $search);
        }

        if (! empty($filters->get('gender'))) {
            $query->where('gender', $filters->get('gender'));
        }

        $scope = $this->accessScope->forUser($user, 'leave_payments');

        if (! $scope->canViewAllZones) {
            $employeeId = $user->employee?->id;
            if (! $scope->hasZoneScope()) {
                $query->where('id', $employeeId);
            } else {
                $query->where(function ($q) use ($scope, $employeeId) {
                    $q->where(function ($inner) use ($scope) {
                        if ($scope->allowedZoneIds !== []) {
                            $inner->orWhereIn('zone_id', $scope->allowedZoneIds);
                        }
                        if ($scope->allowedDepartmentIds !== []) {
                            $inner->orWhereIn('department_id', $scope->allowedDepartmentIds);
                        }
                    });
                    if ($employeeId !== null) {
                        $q->orWhere('id', $employeeId);
                    }
                });
            }
        }

        return $query->get();
    }

    protected function applyRequestWindow($query, LeaveReportFilters $filters): void
    {
        $filters->applyLeaveOverlap($query);
    }

    protected function applyCaseWindow($query, LeaveReportFilters $filters): void
    {
        $filters->applyCreatedOverlap($query);
    }

    /** @return array{pairs:array,buckets:array<string,array>,counts:array<string,int>} */
    protected function emptyAnalysis(): array
    {
        return [
            'pairs' => [],
            'buckets' => ['without_request' => [], 'eligible_no_leave' => [], 'leave_taken_unpaid' => [], 'eligible_unpaid' => []],
            'counts' => ['eligible' => 0, 'without_request' => 0, 'eligible_no_leave' => 0, 'leave_taken_unpaid' => 0, 'eligible_unpaid' => 0],
        ];
    }
}
