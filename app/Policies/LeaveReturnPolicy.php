<?php

namespace App\Policies;

use App\Models\LeaveReturn;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;

/**
 * Return-to-work authorisation (Phase 3).
 *
 * The owner controls their own report and explanation. Review requires the
 * matching permission AND that the case's employee falls inside the reviewer's
 * department/zone scope. Organisation-wide reach still comes only from the
 * explicit leave_returns.view_cross_zone code, exactly like every other module,
 * so a regional officer is never widened above their scope by holding a
 * decision code.
 */
class LeaveReturnPolicy
{
    public function __construct(
        protected PermissionService $permissions,
        protected AccessScopeService $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, 'leave_returns', 'view_own')
            || $this->permissions->can($user, 'leave_returns', 'view_department')
            || $this->permissions->can($user, 'leave_returns', 'view_zone')
            || $this->permissions->can($user, 'leave_returns', 'view_cross_zone')
            || $this->permissions->can($user, 'leave_returns', 'review')
            || $this->permissions->can($user, 'leave_returns', 'close');
    }

    /** The owner always may; otherwise a scoped view code must cover the employee. */
    public function view(User $user, LeaveReturn $return): bool
    {
        if ($this->isOwner($user, $return)) {
            return true;
        }

        if ($this->permissions->can($user, 'leave_returns', 'view_cross_zone')) {
            return true;
        }

        return $this->holdsScopedRead($user) && $this->scopeCoversEmployee($user, $return);
    }

    public function report(User $user, LeaveReturn $return): bool
    {
        return $this->isOwner($user, $return)
            && $this->permissions->can($user, 'leave_returns', 'report_own');
    }

    public function explanation(User $user, LeaveReturn $return): bool
    {
        return $this->report($user, $return);
    }

    public function review(User $user, LeaveReturn $return): bool
    {
        return $this->permissions->can($user, 'leave_returns', 'review')
            && ! $this->isOwner($user, $return)
            && $this->view($user, $return);
    }

    public function close(User $user, LeaveReturn $return): bool
    {
        return $this->permissions->can($user, 'leave_returns', 'close')
            && $this->view($user, $return);
    }

    protected function isOwner(User $user, LeaveReturn $return): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && $return->employee_id === $employeeId;
    }

    protected function holdsScopedRead(User $user): bool
    {
        return $this->permissions->can($user, 'leave_returns', 'view_department')
            || $this->permissions->can($user, 'leave_returns', 'view_zone')
            || $this->permissions->can($user, 'leave_returns', 'review')
            || $this->permissions->can($user, 'leave_returns', 'close');
    }

    protected function scopeCoversEmployee(User $user, LeaveReturn $return): bool
    {
        $employee = $return->employee;

        if (! $employee) {
            return false;
        }

        $scope = $this->scope->forUser($user, 'leave_returns');

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
