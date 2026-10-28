<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Drill-down builders for the Office Reports page.
 *
 * Every summary card and every detail table on the page is computed from
 * these builders with the same LeaveReportFilters instance, so a card total
 * and its detail rows can never disagree about filters, scope or query
 * definitions. Builders return paginatable queries — rows are never loaded
 * into memory before pagination.
 *
 * Semantics mirror the historical frontend aggregates exactly:
 * - date overlap: a row overlaps [from, to] unless it ends before `from` or
 *   starts after `to` (NULL end date = open-ended);
 * - approved-days: status = approved, summed over `days`;
 * - pending: status in (submitted, under_review);
 * - by_zone: all in-range statuses, grouped by employee zone;
 * - by_type: approved + under_review only;
 * - status_mix: counts per status over the whole in-range set.
 *
 * Row shaping is privacy-safe by construction: leave reasons, exception
 * reasons and medical flags are never selected into detail rows (the
 * notification privacy model treats free text as sensitive). Staff rows
 * exclude payroll numbers and email addresses; gender is included only for
 * holders of reports.view_gender_reports (passed in by the caller).
 */
class ReportDetailService
{
    public function __construct(
        protected AccessScopeService $accessScope,
    ) {}

    /**
     * Shared in-range, in-scope request base. Every category below starts
     * here, so summary and detail share one definition.
     *
     * @param  array{leave_status?:?string,leave_type_id?:?string}  $extra
     */
    public function baseRequests(User $user, LeaveReportFilters $filters, array $extra = []): Builder
    {
        $query = LeaveRequest::query()
            ->with(['employee.department', 'employee.zone', 'leaveType']);

        $filters->applyLeaveOverlap($query);
        $filters->applyIdentities($query);

        if (! empty($extra['leave_status'])) {
            $query->where('status', $extra['leave_status']);
        }

        if (! empty($extra['leave_type_id'])) {
            $query->where('leave_requests.leave_type_id', $extra['leave_type_id']);
        }

        if (! empty($filters->get('department_id'))) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $filters->get('department_id')));
        }

        if (! empty($filters->get('zone_id'))) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('zone_id', $filters->get('zone_id')));
        }

        if (! empty($filters->get('search'))) {
            $search = '%'.str_replace(['%', '_'], '', (string) $filters->get('search')).'%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('ref', 'like', $search)
                    ->orWhereHas('employee', fn (Builder $e) => $e->where('full_name', 'like', $search));
            });
        }

        $this->accessScope->applyLeaveRequestScope($query, $user, AccessScopeService::REPORTS);

        return $query;
    }

    /** Approved-days detail base (also feeds the card total). */
    public function approvedDays(User $user, LeaveReportFilters $filters): Builder
    {
        return $this->baseRequests($user, $filters, ['leave_status' => 'approved']);
    }

    /** Pending detail base: submitted + under_review, exactly like the card. */
    public function pending(User $user, LeaveReportFilters $filters): Builder
    {
        return $this->baseRequests($user, $filters)->whereIn('status', ['submitted', 'under_review']);
    }

    /** Requests-logged detail base: every in-range status. */
    public function loggedRequests(User $user, LeaveReportFilters $filters): Builder
    {
        return $this->baseRequests($user, $filters);
    }

    /** Gender-group detail base: approved rows whose employee has the gender. */
    public function genderGroup(User $user, LeaveReportFilters $filters, string $gender): Builder
    {
        return $this->approvedDays($user, $filters)
            ->whereHas('employee', fn (Builder $q) => $q->where('gender', $gender));
    }

    /** Zone-usage detail base: all in-range statuses for one zone. */
    public function zoneUsage(User $user, LeaveReportFilters $filters, string $zoneId): Builder
    {
        return $this->baseRequests($user, $filters)
            ->whereHas('employee', fn (Builder $q) => $q->where('zone_id', $zoneId));
    }

    /** Type-usage detail base: approved + under_review of one leave type. */
    public function typeUsage(User $user, LeaveReportFilters $filters, string $leaveTypeId): Builder
    {
        return $this->baseRequests($user, $filters, ['leave_type_id' => $leaveTypeId])
            ->whereIn('status', ['approved', 'under_review']);
    }

    /** Status-mix detail base: every in-range row with one status. */
    public function statusGroup(User $user, LeaveReportFilters $filters, string $status): Builder
    {
        return $this->baseRequests($user, $filters, ['leave_status' => $status]);
    }

    /** Staff base: active employees in STAFF scope. */
    public function staff(User $user, LeaveReportFilters $filters): Builder
    {
        $query = Employee::query()->with(['department', 'zone'])->where('is_active', true);

        if (! empty($filters->get('department_id'))) {
            $query->where('department_id', $filters->get('department_id'));
        }

        if (! empty($filters->get('zone_id'))) {
            $query->where('zone_id', $filters->get('zone_id'));
        }

        if (! empty($filters->get('employee_id'))) {
            $query->where('id', $filters->get('employee_id'));
        }

        if (! empty($filters->get('search'))) {
            $search = '%'.str_replace(['%', '_'], '', (string) $filters->get('search')).'%';
            $query->where('full_name', 'like', $search);
        }

        $this->accessScope->applyEmployeeScope($query, $user);

        return $query;
    }

    /**
     * Card + chart aggregates from the same builders the detail tables
     * paginate. Totals are computed in SQL (count/sum), never by loading rows.
     *
     * @return array<string,mixed>
     */
    public function overview(User $user, LeaveReportFilters $filters, bool $canSeeGender): array
    {
        $approvedBase = $this->approvedDays($user, $filters);
        $pendingBase = $this->pending($user, $filters);
        $loggedBase = $this->loggedRequests($user, $filters);
        $staffBase = $this->staff($user, $filters);

        $gender = null;
        if ($canSeeGender) {
            $femaleBase = $this->genderGroup($user, $filters, 'F');
            $maleBase = $this->genderGroup($user, $filters, 'M');
            $gender = [
                'F' => (float) (clone $femaleBase)->sum('days'),
                'M' => (float) (clone $maleBase)->sum('days'),
            ];
        }

        return [
            'cards' => [
                'approved_days' => ['total' => (float) (clone $approvedBase)->sum('days')],
                'pending' => ['total' => (clone $pendingBase)->count()],
                'staff' => ['total' => (clone $staffBase)->count()],
                'gender' => $gender,
                'requests_logged' => ['total' => (clone $loggedBase)->count()],
            ],
            'by_zone' => $this->zoneAggregates($user, $filters),
            'by_type' => $this->typeAggregates($user, $filters),
            'status_mix' => $this->statusAggregates($user, $filters),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    protected function zoneAggregates(User $user, LeaveReportFilters $filters): array
    {
        $rows = $this->baseRequests($user, $filters)
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->leftJoin('zones', 'zones.id', '=', 'employees.zone_id')
            ->selectRaw('employees.zone_id as zone_id, zones.short as zone, zones.name as zone_name, COUNT(*) as requests, COALESCE(SUM(leave_requests.days), 0) as days')
            ->groupBy('employees.zone_id', 'zones.short', 'zones.name')
            ->orderByDesc('days')
            ->get();

        return $rows->map(fn ($r) => [
            'zone_id' => $r->zone_id,
            'zone' => $r->zone ?? $r->zone_id,
            'requests' => (int) $r->requests,
            'days' => (float) $r->days,
        ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function typeAggregates(User $user, LeaveReportFilters $filters): array
    {
        $rows = $this->baseRequests($user, $filters)
            ->whereIn('leave_requests.status', ['approved', 'under_review'])
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->selectRaw('leave_requests.leave_type_id as leave_type_id, leave_types.short as name, COALESCE(SUM(leave_requests.days), 0) as days')
            ->groupBy('leave_requests.leave_type_id', 'leave_types.short')
            ->orderByDesc('days')
            ->get();

        return $rows->map(fn ($r) => [
            'leave_type_id' => $r->leave_type_id,
            'name' => $r->name,
            'days' => (float) $r->days,
        ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function statusAggregates(User $user, LeaveReportFilters $filters): array
    {
        $rows = $this->baseRequests($user, $filters)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        return $rows->map(fn ($r) => [
            'status' => $r->status,
            'count' => (int) $r->count,
        ])->all();
    }

    /**
     * Privacy-safe request row. Free-text and medical fields (reason,
     * exception_reason, medical flags, emergency flags) are deliberately
     * absent — detail tables expose facts, never narratives.
     *
     * @return array<string,mixed>
     */
    public static function shapeRequestRow(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'ref' => $r->ref,
            'employee' => $r->employee ? [
                'id' => $r->employee->id,
                'full_name' => $r->employee->full_name,
            ] : null,
            'department' => $r->employee?->department ? [
                'id' => $r->employee->department->id,
                'name' => $r->employee->department->name,
            ] : null,
            'zone' => $r->employee?->zone ? [
                'id' => $r->employee->zone->id,
                'name' => $r->employee->zone->name,
                'short' => $r->employee->zone->short,
            ] : null,
            'leave_type' => $r->leaveType ? [
                'id' => $r->leaveType->id,
                'code' => $r->leaveType->code,
                'name' => $r->leaveType->name,
            ] : null,
            'days' => $r->days !== null ? (float) $r->days : null,
            'status' => $r->status,
            'start_date' => $r->start_date?->toDateString(),
            'end_date' => $r->end_date?->toDateString(),
            'submitted_at' => $r->submitted_at?->toDateString(),
        ];
    }

    /**
     * Privacy-safe staff row. Payroll numbers and email never leave the
     * server here; gender only when the caller may see restricted data.
     *
     * @return array<string,mixed>
     */
    public static function shapeStaffRow(Employee $e, bool $canSeeGender): array
    {
        return [
            'id' => $e->id,
            'full_name' => $e->full_name,
            'initials' => $e->initials,
            'department' => $e->department ? [
                'id' => $e->department->id,
                'name' => $e->department->name,
            ] : null,
            'zone' => $e->zone ? [
                'id' => $e->zone->id,
                'name' => $e->zone->name,
                'short' => $e->zone->short,
            ] : null,
            'gender' => $canSeeGender ? $e->gender : null,
            'hire_date' => $e->hire_date?->toDateString(),
        ];
    }
}
