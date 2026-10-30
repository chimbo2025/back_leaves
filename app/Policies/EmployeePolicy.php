<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;

class EmployeePolicy
{
    public function __construct(
        protected AccessScopeService $scope,
        protected PermissionService $permissions,
    ) {}

    /**
     * Staff-directory visibility: self, plus the caller's zone/department
     * scope, plus org-wide for holders of staff.view_cross_zone.
     */
    public function view(User $user, Employee $employee): bool
    {
        $employeeId = $user->employee?->id;

        if ($employeeId !== null && $employee->id === $employeeId) {
            return true;
        }

        $scope = $this->scope->forUser($user, AccessScopeService::STAFF);

        if ($scope->canViewAllZones) {
            return true;
        }

        if (! $scope->hasZoneScope()) {
            return false;
        }

        return in_array($employee->zone_id, $scope->allowedZoneIds, true)
            || in_array($employee->department_id, $scope->allowedDepartmentIds, true);
    }

    /**
     * Hard deletion is a separate capability from ordinary administration.
     * It is deliberately never self-service: an administrator must ask
     * somebody else to remove their own record.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        return $this->permissions->can($user, 'employees', 'force_delete')
            && $employee->id !== $user->employee?->id;
    }
}
