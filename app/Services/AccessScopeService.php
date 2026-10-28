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

        return in_array((string) $employee->zone_id, array_map('strval', $scope->allowedZoneIds), true)
            || in_array((string) $employee->department_id, array_map('strval', $scope->allowedDepartmentIds), true);
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

        if (! $scope->hasZoneScope()) {
            return $query->where('employee_id', $employeeId);
        }

        return $query->where(function (Builder $outer) use ($scope, $employeeId) {
            $outer->whereHas('employee', function (Builder $emp) use ($scope) {
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
                $outer->orWhere('employee_id', $employeeId);
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
