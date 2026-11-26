<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Zone/department data-visibility resolution.
 *
 * ASSUMPTION (documented per the implementation brief): organisation-wide
 * reach is granted ONLY by an explicit `<module>.view_cross_zone` permission
 * code. Neither Zone.is_hq nor a NULL/NULL employee_roles row is treated as a
 * grant — the seeded SUPERVISOR/HR rows carry NULL/NULL but that records
 * "scope unspecified", not "all zones". This keeps the rule deny-by-default.
 *
 * Precedence: cross-zone permission > scope_zone_id > department_id > own
 * records only. Zone and department grants are unioned across all active
 * role assignments of the employee.
 */
class AccessScopeService
{
    public const REQUESTS = 'requests';

    public const STAFF = 'staff';

    public const REPORTS = 'reports';

    public const LEAVE_DOCUMENTS = 'leave_documents';

    public const CROSS_ZONE_ACTION = 'view_cross_zone';

    public function __construct(protected PermissionService $permissions) {}

    /**
     * Resolve the visibility scope for a user within one module.
     */
    public function forUser(User $user, string $module = self::REQUESTS): AccessScope
    {
        $employee = $user->employee;

        if (! $employee) {
            return AccessScope::ownRecords();
        }

        if ($this->canViewAllZones($user, $module)) {
            return new AccessScope(canViewAllZones: true);
        }

        // An archived role or a withdrawn assignment must not widen scope either.
        // Without these two conditions archiving a ZONAL_HEAD role would leave
        // every holder reading the whole zone, because the rows would still
        // carry scope_zone_id even though they grant nothing.
        $grants = EmployeeRole::query()
            ->where('employee_id', $employee->id)
            ->where('active', true)
            ->whereHas('role', fn ($q) => $q->where('active', true))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->get(['department_id', 'scope_zone_id']);

        $zoneIds = $grants->pluck('scope_zone_id')->filter()->unique()->values()->all();
        $deptIds = $grants->pluck('department_id')->filter()->unique()->values()->all();

        if ($zoneIds === [] && $deptIds === []) {
            return AccessScope::ownRecords();
        }

        return new AccessScope(
            canViewAllZones: false,
            allowedZoneIds: $zoneIds,
            allowedDepartmentIds: $deptIds,
        );
    }

    /** Explicit central-permission check; the only route to org-wide data. */
    public function canViewAllZones(User $user, string $module = self::REQUESTS): bool
    {
        return $this->permissions->can($user, $module, self::CROSS_ZONE_ACTION);
    }

    /**
     * Single-record variant of the query filters: does this scope reach THIS
     * employee's rows? Used where a list cannot be constrained up-front (the
     * assignment inbox) and a per-row honesty flag is needed instead. Own
     * records are the caller's business to recognise — pass them in already
     * excluded or compare the ids yourself; this answers the grant check only.
     */
    public function coversEmployee(AccessScope $scope, ?Employee $employee): bool
    {
        if ($scope->canViewAllZones) {
            return true;
        }

        if (! $employee) {
            return false;
        }

        $zoneIds = array_map('strval', $scope->allowedZoneIds);
        $orgIds = array_map('strval', $scope->allowedDepartmentIds);

        // A grant's `department_id` may name a department, a division or a unit
        // (head appointments for all three store the unit id in that column),
        // while an employee carries those placements in three separate columns.
        return in_array((string) $employee->zone_id, $zoneIds, true)
            || in_array((string) $employee->department_id, $orgIds, true)
            || in_array((string) $employee->division_id, $orgIds, true)
            || in_array((string) $employee->unit_id, $orgIds, true);
    }

    /**
     * Constrain a LeaveRequest query to the caller's zone/department scope.
     *
     * The caller's own rows are always retained so a scoped approver can never
     * lose sight of their own request; this cannot widen exposure because own
     * rows are already readable through the unscoped branch.
     */
    public function applyLeaveRequestScope(Builder $query, User $user, string $module = self::REQUESTS): Builder
    {
        $scope = $this->forUser($user, $module);

        if ($scope->canViewAllZones) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        // The leave-request list has its own rule (see applyRequestListScope).
        // Reports, plans and waivers share this method with other modules and
        // keep the behaviour below unchanged.
        if ($module === self::REQUESTS && $query->getModel() instanceof \App\Models\LeaveRequest) {
            return $this->applyRequestListScope($query, $scope, $employeeId);
        }

        if (! $scope->hasZoneScope()) {
            return $query->where('employee_id', $employeeId);
        }

        $isLeaveRequest = $query->getModel() instanceof \App\Models\LeaveRequest;

        return $query->where(function (Builder $outer) use ($scope, $employeeId, $isLeaveRequest) {
            // Geographic condition: employee must be in the granted zone/department,
            // or the request must be the caller's own.
            $outer->where(function (Builder $geo) use ($scope, $employeeId) {
                $geo->whereHas('employee', function (Builder $emp) use ($scope) {
                    $emp->where(function (Builder $inner) use ($scope) {
                        if ($scope->allowedZoneIds !== []) {
                            $inner->orWhereIn('zone_id', $scope->allowedZoneIds);
                        }

                        if ($scope->allowedDepartmentIds !== []) {
                            $inner->orWhereIn('department_id', $scope->allowedDepartmentIds);
                        }
                    });
                });

                if ($employeeId !== null) {
                    $geo->orWhere('employee_id', $employeeId);
                }
            });

            // Assignment condition: the caller must be an assigned approver of the
            // request (or the requester). Only applies to leave requests, which
            // carry a workflow assignment chain; other entities (plans, etc.)
            // rely on the geographic condition alone.
            if ($isLeaveRequest) {
                $outer->where(function (Builder $assign) use ($employeeId) {
                    if ($employeeId === null) {
                        $assign->whereRaw('1 = 0');

                        return;
                    }

                    $assign->where('employee_id', $employeeId)
                        ->orWhereHas('assignments', function (Builder $a) use ($employeeId) {
                            $a->where('assigned_employee_id', $employeeId);
                        });
                });
            }
        });
    }

    /**
     * Visibility rule for the Leave Requests list. A request is visible when
     * ANY of these holds:
     *
     *  1. it is the caller's own;
     *  2. the caller is an assignee on THIS request's approval chain (a step
     *     that was skipped does not count) — this is what lets a central role
     *     with no zone/department/division/unit see the requests it approves;
     *  3. the requester sits inside an organisational scope the caller holds an
     *     explicit grant for (zone, department, division or unit).
     *
     * Holding an approval role, or merely working in the same department,
     * grants nothing by itself. This matches LeaveRequestPolicy::view, so the
     * list never hides (or reveals) a request the detail endpoint would not.
     * Org-wide reach is handled before this method, via view_cross_zone only.
     */
    protected function applyRequestListScope(Builder $query, AccessScope $scope, ?string $employeeId): Builder
    {
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($scope, $employeeId) {
            $outer->where('employee_id', $employeeId)
                ->orWhereHas('assignments', function (Builder $a) use ($employeeId) {
                    // A `skipped` step was never put to this person, so it is
                    // not a workflow assignment that justifies seeing the request.
                    $a->where('assigned_employee_id', $employeeId)
                        ->where('status', '!=', 'skipped');
                });

            if ($scope->hasZoneScope()) {
                $outer->orWhereHas('employee', function (Builder $emp) use ($scope) {
                    $emp->where(function (Builder $inner) use ($scope) {
                        if ($scope->allowedZoneIds !== []) {
                            $inner->orWhereIn('zone_id', $scope->allowedZoneIds);
                        }

                        if ($scope->allowedDepartmentIds !== []) {
                            $inner->orWhereIn('department_id', $scope->allowedDepartmentIds)
                                ->orWhereIn('division_id', $scope->allowedDepartmentIds)
                                ->orWhereIn('unit_id', $scope->allowedDepartmentIds);
                        }
                    });
                });
            }
        });
    }

    /** Same contract for an Employee query (zone_id/department_id live on the row itself). */
    public function applyEmployeeScope(Builder $query, User $user): Builder
    {
        $scope = $this->forUser($user, self::STAFF);

        if ($scope->canViewAllZones) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if (! $scope->hasZoneScope()) {
            return $query->where('id', $employeeId);
        }

        return $query->where(function (Builder $outer) use ($scope, $employeeId) {
            $outer->where(function (Builder $inner) use ($scope) {
                if ($scope->allowedZoneIds !== []) {
                    $inner->orWhereIn('zone_id', $scope->allowedZoneIds);
                }

                if ($scope->allowedDepartmentIds !== []) {
                    $inner->orWhereIn('department_id', $scope->allowedDepartmentIds);
                }
            });

            if ($employeeId !== null) {
                $outer->orWhere('id', $employeeId);
            }
        });
    }

    /**
     * Zone/department predicate for raw aggregate queries (reports), which join
     * `employees` directly rather than going through an Eloquent relation.
     *
     * @param  array<int,string>  $zoneIds
     * @param  array<int,string>  $deptIds
     */
    public function applyEmployeeColumnScope(Builder $query, string $alias, array $zoneIds, array $deptIds): Builder
    {
        return $query->where(function (Builder $outer) use ($alias, $zoneIds, $deptIds) {
            $outer->where(function (Builder $inner) use ($alias, $zoneIds, $deptIds) {
                if ($zoneIds !== []) {
                    $inner->orWhereIn("{$alias}.zone_id", $zoneIds);
                }

                if ($deptIds !== []) {
                    $inner->orWhereIn("{$alias}.department_id", $deptIds);
                }
            });
        });
    }
}
