<?php

namespace App\Policies;

use App\Models\LeavePaymentCase;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;

/**
 * Leave-payment case authorisation (Phase 5).
 *
 * Read access mirrors every other module: the subject of a payment case can
 * always read their own; a scoped reader must additionally cover the employee's
 * zone/department; organisation-wide reach comes only from the explicit
 * leave_payments.view_cross_zone code. A decision code (approve, reject,
 * receive, process) never widens *visibility* — it only allows acting on a case
 * the user can already see, which is why the decision methods call view() rather
 * than the other way round.
 *
 * The write transitions are gated by route middleware on the specific code; this
 * policy is the read gate and the defensive backstop.
 */
class LeavePaymentCasePolicy
{
    public function __construct(
        protected PermissionService $permissions,
        protected AccessScopeService $scope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'view_own')
            || $this->permissions->can($user, 'leave_payments', 'view_department')
            || $this->permissions->can($user, 'leave_payments', 'view_zone')
            || $this->permissions->can($user, 'leave_payments', 'view_cross_zone')
            || $this->permissions->can($user, 'leave_payments', 'create')
            || $this->permissions->can($user, 'leave_payments', 'review')
            || $this->permissions->can($user, 'leave_payments', 'approve')
            || $this->permissions->can($user, 'leave_payments', 'receive')
            || $this->permissions->can($user, 'leave_payments', 'process')
            || $this->permissions->can($user, 'leave_payments', 'cancel');
    }

    /** The subject always may; otherwise a scoped read code must cover the employee. */
    public function view(User $user, LeavePaymentCase $case): bool
    {
        if ($this->isSubject($user, $case)) {
            return true;
        }

        if ($this->permissions->can($user, 'leave_payments', 'view_cross_zone')) {
            return true;
        }

        return $this->holdsScopedRead($user) && $this->scopeCoversEmployee($user, $case);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'create');
    }

    public function evaluate(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'create') && $this->view($user, $case);
    }

    public function submit(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'create') && $this->view($user, $case);
    }

    public function approve(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'approve') && $this->view($user, $case);
    }

    public function reject(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'reject') && $this->view($user, $case);
    }

    public function sendToAccounting(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'review') && $this->view($user, $case);
    }

    public function receive(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'receive') && $this->view($user, $case);
    }

    public function process(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'process') && $this->view($user, $case);
    }

    public function cancel(User $user, LeavePaymentCase $case): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'cancel') && $this->view($user, $case);
    }

    protected function isSubject(User $user, LeavePaymentCase $case): bool
    {
        $employeeId = $user->employee?->id;

        return $employeeId !== null && $case->employee_id === $employeeId;
    }

    protected function holdsScopedRead(User $user): bool
    {
        return $this->permissions->can($user, 'leave_payments', 'view_own')
            || $this->permissions->can($user, 'leave_payments', 'view_department')
            || $this->permissions->can($user, 'leave_payments', 'view_zone')
            || $this->permissions->can($user, 'leave_payments', 'review')
            || $this->permissions->can($user, 'leave_payments', 'approve')
            || $this->permissions->can($user, 'leave_payments', 'receive')
            || $this->permissions->can($user, 'leave_payments', 'process')
            || $this->permissions->can($user, 'leave_payments', 'cancel');
    }

    protected function scopeCoversEmployee(User $user, LeavePaymentCase $case): bool
    {
        $employee = $case->employee;

        if (! $employee) {
            return false;
        }

        $scope = $this->scope->forUser($user, 'leave_payments');

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
