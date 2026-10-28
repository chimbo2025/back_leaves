<?php

namespace App\Policies;

use App\Models\LeaveWaiver;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;

/**
 * Waiver authorisation (Phase 2 balance decision).
 *
 * Owners control their own waivers (create/submit/cancel). Review, approve and
 * reject require the matching permission AND that the waiver's employee falls
 * inside the reviewer's department/zone scope. Organisation-wide reach still
 * comes only from the explicit leave_waivers.view_cross_zone code, exactly like
 * requests/staff/reports/leave_plans, so a regional officer is never widened
 * above their scope by holding a decision code.
 */
class LeaveWaiverPolicy
{
    public function __construct(
        protected PermissionService $permissions,
        protected AccessScopeService $scope,
    ) {}

    public function create(User $user): bool
    {
        return $this->permissions->can($user, 'leave_waivers', 'create_own');
    }

    public function viewAny(User $user): bool
    {
        foreach (['view_own', 'view_department', 'view_zone', 'view_cross_zone', 'review', 'approve', 'reject'] as $action) {
            if ($this->permissions->can($user, 'leave_waivers', $action)) {
                return true;
            }
        }

        return false;
    }

    /** The owner always may; otherwise a scoped view code must cover the employee. */
    public function view(User $user, LeaveWaiver $waiver): bool
    {
        if ($this->isOwner($user, $waiver)) {
            return true;
        }

        if ($this->permissions->can($user, 'leave_waivers', 'view_cross_zone')) {
            return true;
        }

        return $this->holdsScopedRead($user) && $this->scopeCoversEmployee($user, $waiver);
    }

    public function submit(User $user, LeaveWaiver $waiver): bool
    {
        return $this->isOwner($user, $waiver)
            && $this->permissions->can($user, 'leave_waivers', 'create_own');
    }

    public function review(User $user, LeaveWaiver $waiver): bool
    {
        return $this->permissions->can($user, 'leave_waivers', 'review')
            && ! $this->isOwner($user, $waiver)
            && $this->view($user, $waiver);
    }

    public function approve(User $user, LeaveWaiver $waiver): bool
    {
        return $this->permissions->can($user, 'leave_waivers', 'approve')
            && ! $this->isOwner($user, $waiver)
            && $this->view($user, $waiver);
    }

    public function reject(User $user, LeaveWaiver $waiver): bool
    {
        return $this->permissions->can($user, 'leave_waivers', 'reject')
            && ! $this->isOwner($user, $waiver)
            && $this->view($user, $waiver);
    }

    public function cancel(User $user, LeaveWaiver $waiver): bool
    {
        return $this->isOwner($user, $waiver);
    }

    protected function isOwner(User $user, LeaveWaiver $waiver): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && $waiver->employee_id === $employeeId;
    }

    protected function holdsScopedRead(User $user): bool
    {
        foreach (['view_department', 'view_zone', 'review', 'approve', 'reject'] as $action) {
            if ($this->permissions->can($user, 'leave_waivers', $action)) {
                return true;
            }
        }

        return false;
    }

    protected function scopeCoversEmployee(User $user, LeaveWaiver $waiver): bool
    {
        $employee = $waiver->employee;

        if (! $employee) {
            return false;
        }

        $scope = $this->scope->forUser($user, 'leave_waivers');

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
