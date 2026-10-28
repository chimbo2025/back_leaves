<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDate;
use App\Models\User;
use App\Models\WorkflowAssignment;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Blueprint Part 4 + frontend Reports view. All aggregates operate on
 * APPROVED request dates (leave_request_dates) so fractions/half-days and
 * overlapping requests are counted once. Nothing here auto-decides; tips are
 * advisory.
 *
 * ZONE SCOPING: every aggregate is constrained to the caller's zone/
 * department scope. Organisation-wide figures require reports.view_cross_zone
 * (HR, FINAL_AUTHORITY); everyone else sees only their own office, and a user
 * with no zone/department grant sees only their own records.
 */
class ReportService
{
    protected const APPROVED = 'approved';

    public function __construct(protected AccessScopeService $accessScope) {}

    /**
     * Apply report-level zone/department scoping to a query that already
     * joins (or is) the employees table under $alias.
     */
    protected function restrict(Builder $query, User $user, string $alias = 'employees'): Builder
    {
        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);

        if ($scope->canViewAllZones) {
            return $query;
        }

        if (! $scope->hasZoneScope()) {
            return $query->where("{$alias}.id", $user->employee?->id);
        }

        return $this->accessScope->applyEmployeeColumnScope($query, $alias, $scope->allowedZoneIds, $scope->allowedDepartmentIds);
    }

    /** Same contract for Eloquent LeaveRequest queries. */
    protected function restrictRequests(Builder $query, User $user): Builder
    {
        return $this->accessScope->applyLeaveRequestScope($query, $user, AccessScopeService::REPORTS);
    }

    public function usageByZone(string $from, string $to, User $user): array
    {
        return $this->usageGrouped('zone_id', $from, $to, $user);
    }

    protected function usageGrouped(string $column, string $from, string $to, User $user): array
    {
        $label = $column === 'zone_id' ? 'zone' : 'department';
        $table = $column === 'zone_id' ? 'zones' : 'departments';

        $query = LeaveRequestDate::query()
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_dates.request_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->leftJoin($table, "{$table}.id", '=', "employees.{$column}")
            ->where('leave_requests.status', self::APPROVED)
            ->whereBetween('leave_request_dates.the_date', [$from, $to]);

        $this->restrict($query, $user);

        return $query->select(
            "employees.{$column}",
            DB::raw('SUM(leave_request_dates.day_fraction) as days'),
            DB::raw('COUNT(DISTINCT leave_requests.employee_id) as employees'),
            DB::raw('COUNT(DISTINCT leave_request_dates.request_id) as requests'),
        )
            ->groupBy("employees.{$column}")
            ->orderByDesc('days')
            ->get()
            ->map(fn ($r) => [
                $label.'_id' => $r->{$column},
                'name' => $r->{$column} ? $this->nameFor($table, $r->{$column}) : 'Unassigned',
                'days' => round((float) $r->days, 1),
                'employees' => (int) $r->employees,
                'requests' => (int) $r->requests,
            ])
            ->all();
    }

    protected function nameFor(string $table, ?string $id): string
    {
        if (! $id) {
            return 'Unassigned';
        }

        return ($table === 'zones' ? Zone::find($id) : Department::find($id))?->name ?? $id;
    }

    public function usageByDepartment(string $from, string $to, User $user): array
    {
        return $this->usageGrouped('department_id', $from, $to, $user);
    }

    public function usageByType(string $from, string $to, User $user): array
    {
        $query = LeaveRequestDate::query()
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_dates.request_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('leave_requests.status', self::APPROVED)
            ->whereBetween('leave_request_dates.the_date', [$from, $to]);

        $this->restrict($query, $user);

        return $query->select('leave_types.code', 'leave_types.name', DB::raw('SUM(leave_request_dates.day_fraction) as days'), DB::raw('COUNT(DISTINCT leave_request_dates.request_id) as requests'))
            ->groupBy('leave_types.id', 'leave_types.code', 'leave_types.name')
            ->orderByDesc('days')
            ->get()
            ->map(fn ($r) => ['leave_type' => $r->code, 'name' => $r->name, 'days' => round((float) $r->days, 1), 'requests' => (int) $r->requests])
            ->all();
    }

    public function genderReport(string $from, string $to, User $user): array
    {
        $query = Employee::query()->where('is_active', true);

        $this->restrict($query, $user);

        return $query->withCount([
            'leaveRequests' => fn ($q) => $q->where('status', self::APPROVED)->where('start_date', '<=', $to)->where('end_date', '>=', $from),
        ])
            ->get()
            ->groupBy('gender')
            ->map(fn ($group, $gender) => [
                'gender' => $gender,
                'employees' => $group->count(),
                'requests' => $group->sum('leave_requests_count'),
            ])
            ->values()
            ->all();
    }

    /** Pending assignments grouped by role with over-SLA counts (display only). */
    public function bottleneck(?Carbon $asOf, User $user): array
    {
        $asOf = $asOf ?? now();

        $query = WorkflowAssignment::query()
            ->where('status', 'pending')
            ->join('leave_requests', 'leave_requests.id', '=', 'workflow_assignments.request_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->where('leave_requests.status', 'under_review');

        $this->restrict($query, $user);

        return $query->select(
            'workflow_assignments.assigned_role_id as role',
            DB::raw('COUNT(*) as pending'),
            DB::raw("SUM(CASE WHEN workflow_assignments.due_at < '{$asOf->toDateTimeString()}' THEN 1 ELSE 0 END) as overdue"),
        )
            ->groupBy('workflow_assignments.assigned_role_id')
            ->orderByDesc('pending')
            ->get()
            ->map(fn ($r) => [
            'role' => $r->role,
            'pending' => (int) $r->pending,
            'overdue' => (int) $r->overdue,
            'tip' => (int) $r->overdue > 0 ? "{$r->overdue} assignment(s) exceed SLA — reassign or chase." : null,
        ])
            ->all();
    }

    /** Approved leaves starting within the lookahead window, per employee. */
    public function upcoming(string $from, string $to, User $user): array
    {
        $query = LeaveRequest::query()
            ->where('status', self::APPROVED)
            ->whereBetween('start_date', [$from, $to]);

        $this->restrictRequests($query, $user);

        return $query->with(['employee', 'leaveType'])
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $lr) => [
                'ref' => $lr->ref,
                'employee' => $lr->employee?->full_name,
                'department_id' => $lr->employee?->department_id,
                'zone_id' => $lr->employee?->zone_id,
                'leave_type' => $lr->leaveType?->code,
                'start_date' => $lr->start_date->toDateString(),
                'end_date' => $lr->end_date->toDateString(),
                'days' => (int) $lr->days,
            ])
            ->all();
    }

    /** Approved leave days per calendar month (from the exact date rows). */
    public function monthly(string $from, string $to, User $user): array
    {
        $query = LeaveRequestDate::query()
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_dates.request_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->where('leave_requests.status', self::APPROVED)
            ->whereBetween('leave_request_dates.the_date', [$from, $to]);

        $this->restrict($query, $user);

        // DATE_FORMAT is MySQL-only; SQLite (test database) uses strftime.
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', leave_request_dates.the_date)"
            : "DATE_FORMAT(leave_request_dates.the_date, '%Y-%m')";

        return $query->select(DB::raw($monthExpr.' as month'), DB::raw('SUM(leave_request_dates.day_fraction) as days'), DB::raw('COUNT(DISTINCT leave_requests.employee_id) as employees'))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'days' => round((float) $r->days, 1), 'employees' => (int) $r->employees])
            ->all();
    }

    /** Projected coverage (approved + under_review) per department per month. */
    public function forecast(string $from, string $to, User $user): array
    {
        $query = LeaveRequest::query()
            ->whereIn('status', [self::APPROVED, 'under_review'])
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from);

        $this->restrictRequests($query, $user);

        return $query->with('employee.department')
            ->get()
            ->groupBy(fn (LeaveRequest $lr) => $lr->employee?->department_id ?? 'unknown')
            ->map(function ($requests, $deptId) {
                $dept = Department::find($deptId);

                return [
                    'department' => $dept?->name ?? 'Unknown',
                    'requests' => $requests->count(),
                    'days' => (float) $requests->sum('days'),
                    'window' => [
                        'earliest' => $requests->min('start_date'),
                        'latest' => $requests->max('end_date'),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /** Absence calendar: which employees are away on each date in range. */
    public function staffingCoverage(string $from, string $to, User $user): array
    {
        $query = LeaveRequestDate::query()
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_dates.request_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->where('leave_requests.status', self::APPROVED)
            ->whereBetween('leave_request_dates.the_date', [$from, $to]);

        $this->restrict($query, $user);

        return $query->select('the_date', 'employees.department_id', 'employees.full_name', 'leave_request_dates.day_fraction')
            ->orderBy('the_date')
            ->get()
            ->groupBy('the_date')
            ->map(function ($rows, $date) {
                $byDept = $rows->groupBy('department_id')->map(fn ($d) => $d->count())->sortDesc();

                return [
                    'date' => $date,
                    'absent_total' => $rows->count(),
                    'per_department' => $byDept->map(fn ($n, $d) => ['department_id' => $d, 'absent' => $n])->values(),
                    'tip' => $rows->count() >= 5 ? "Heavy absence on {$date} — check staffing." : null,
                ];
            })
            ->values()
            ->all();
    }
}
