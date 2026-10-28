<?php

namespace App\Policies;

use App\Models\LeavePlan;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;

/**
 * Leave-plan authorisation (PART C).
 *
 * - An employee manages their own plans (create_own / select_own).
 * - Department/zonal heads view and confirm plans for staff inside their
 *   employee_roles department/zone grants (view_department / view_zone /
 *   confirm_department / confirm_zone).
 * - Organisation-wide reach on the plans module comes only from the explicit
 *   leave_plans.view_cross_zone code, exactly like requests/staff/reports, so
 *   no regional role silently widens above its scope.
 */
class LeavePlanPolicy
{
    public function __construct(
        protected AccessScopeService $scope,
        protected PermissionService $permissions,
    ) {}

    public function create(User $user): bool
    {
        return $this->permissions->can($user, 'leave_plans', 'create_own');
    }

    public function propose(User $user, LeavePlan $plan): bool
    {
        return $this->permissions->can($user, 'leave_plans', 'create_own')
            && $plan->employee_id === $user->employee?->id;
    }

    public function select(User $user, LeavePlan $plan): bool
    {
        return $this->permissions->can($user, 'leave_plans', 'select_own')
            && $plan->employee_id === $user->employee?->id;
    }

    public function convert(User $user, LeavePlan $plan): bool
    {
        return $this->permissions->can($user, 'leave_plans', 'select_own')
            && $plan->employee_id === $user->employee?->id;
    }

    /** Listing window: any plans module read code is enough to open the list. */
    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, 'leave_plans', 'view_own')
            || $this->permissions->can($user, 'leave_plans', 'view_department')
            || $this->permissions->can($user, 'leave_plans', 'view_zone')
            || $this->permissions->can($user, 'leave_plans', 'view_cross_zone');
    }

    public function view(User $user, LeavePlan $plan): bool
    {
        $employeeId = $user->employee?->id;

        if ($employeeId !== null && $plan->employee_id === $employeeId) {
            return true;
        }

        if ($this->permissions->can($user, 'leave_plans', 'view_cross_zone')) {
            return true;
        }

        if (! $this->permissions->can($user, 'leave_plans', 'view_department')
            && ! $this->permissions->can($user, 'leave_plans', 'view_zone')) {
            return false;
        }

        return $this->scopeCoversEmployee($user, $plan);
    }

    public function confirm(User $user, LeavePlan $plan): bool
    {
        if ($this->permissions->can($user, 'leave_plans', 'view_cross_zone')) {
            return true;
        }

        $inDepartmentScope = $this->permissions->can($user, 'leave_plans', 'confirm_department');
        $inZoneScope = $this->permissions->can($user, 'leave_plans', 'confirm_zone');

        if (! $inDepartmentScope && ! $inZoneScope) {
            return false;
        }

        return $this->scopeCoversEmployee($user, $plan);
    }

    public function cancel(User $user, LeavePlan $plan): bool
    {
        return $this->permissions->can($user, 'leave_plans', 'select_own')
            && $plan->employee_id === $user->employee?->id;
    }

    protected function scopeCoversEmployee(User $user, LeavePlan $plan): bool
    {
        $employee = $plan->employee;

        if (! $employee) {
            return false;
        }

        $scope = $this->scope->forUser($user, 'leave_plans');

        if ($scope->canViewAllZones) {
            return true;
        }

        if (! $scope->hasZoneScope()) {
            return false;
        }

        return in_array($employee->zone_id, $scope->allowedZoneIds, true)
            || in_array($employee->department_id, $scope->allowedDepartmentIds, true);
    }
}
