<?php

namespace App\Policies;

use App\Models\OfficialLeaveLetter;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;

/**
 * Official leave letter authorisation (Phase 6).
 *
 * - The subject of the letter can always view/download their own.
 * - HR with download_hr must also have scope over the employee.
 * - FINAL_AUTHORITY/BREAK_GLASS have cross-zone scope.
 * - Generation is restricted to HR/FINAL_AUTHORITY with generate permission.
 * - Revocation is HR/FINAL_AUTHORITY only.
 * - Public verification requires no auth (handled by verification endpoint).
 */
class OfficialLeaveLetterPolicy
{
    public function __construct(
        protected PermissionService $permissions,
        protected AccessScopeService $scope,
    ) {}

    /** Any employee can list their own letters. */
    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, 'leave_documents', 'view_own')
            || $this->permissions->can($user, 'leave_documents', 'download_own')
            || $this->permissions->can($user, 'leave_documents', 'download_hr')
            || $this->permissions->can($user, 'leave_documents', 'generate')
            || $this->permissions->can($user, 'leave_documents', 'revoke');
    }

    /** The subject always may; otherwise HR scope must cover the employee. */
    public function view(User $user, OfficialLeaveLetter $letter): bool
    {
        if ($this->isSubject($user, $letter)) {
            return true;
        }

        if ($this->permissions->can($user, 'leave_documents', 'download_hr')
            && $this->scope->canViewAllZones($user, 'leave_documents')) {
            return true;
        }

        return $this->hasScopedAccess($user) && $this->scopeCoversEmployee($user, $letter);
    }

    /** Generate requires HR or FINAL_AUTHORITY with generate permission. */
    public function generate(User $user): bool
    {
        return $this->permissions->can($user, 'leave_documents', 'generate');
    }

    /** Download own letter. */
    public function downloadOwn(User $user, OfficialLeaveLetter $letter): bool
    {
        if (! $this->permissions->can($user, 'leave_documents', 'download_own')) {
            return false;
        }

        return $this->isSubject($user, $letter);
    }

    /** Download for HR scope. */
    public function downloadHr(User $user, OfficialLeaveLetter $letter): bool
    {
        if (! $this->permissions->can($user, 'leave_documents', 'download_hr')) {
            return false;
        }

        // Cross-zone holders can download all.
        if ($this->scope->canViewAllZones($user, 'leave_documents')) {
            return true;
        }

        return $this->hasScopedAccess($user) && $this->scopeCoversEmployee($user, $letter);
    }

    /** Revoke requires HR with revoke permission. */
    public function revoke(User $user, OfficialLeaveLetter $letter): bool
    {
        if (! $this->permissions->can($user, 'leave_documents', 'revoke')) {
            return false;
        }

        // Cross-zone holders can revoke all.
        if ($this->scope->canViewAllZones($user, 'leave_documents')) {
            return true;
        }

        return $this->hasScopedAccess($user) && $this->scopeCoversEmployee($user, $letter);
    }

    /** Public verification requires no auth — handled by verification endpoint. */
    public function verifyPublic(): bool
    {
        return true;
    }

    protected function isSubject(User $user, OfficialLeaveLetter $letter): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && $letter->leaveRequest?->employee_id === $employeeId;
    }

    protected function hasScopedAccess(User $user): bool
    {
        return $this->permissions->can($user, 'leave_documents', 'download_hr')
            || $this->permissions->can($user, 'leave_documents', 'generate')
            || $this->permissions->can($user, 'leave_documents', 'revoke');
    }

    protected function scopeCoversEmployee(User $user, OfficialLeaveLetter $letter): bool
    {
        $employee = $letter->leaveRequest?->employee;

        if (! $employee) {
            return false;
        }

        $scope = $this->scope->forUser($user, 'leave_documents');

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
