<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\AuditLog;
use App\Models\BalanceTransaction;
use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeavePlan;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDate;
use App\Models\LeaveReturn;
use App\Models\LeaveType;
use App\Models\LeaveWaiver;
use App\Models\OfficialLeaveLetter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report query service (Phase 7).
 *
 * Centralised query builders for all supported report types.
 * Each method returns a query builder that can be paginated, counted,
 * or exported. Scope and authorization are enforced by the caller
 * (ReportAuthorizationService) before the query is executed.
 */
class ReportQueryService
{
    public const APPROVED = 'approved';

    public function __construct(
        protected AccessScopeService $accessScope,
    ) {}

    // ============================================================
    // Scope helpers
    // ============================================================

    /**
     * Apply zone/department scope to a query that joins employees.
     */
    protected function restrictEmployeeQuery(Builder $query, User $user, string $alias = 'employees'): Builder
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

    /**
     * Apply zone/department scope to a LeaveRequest query.
     */
    protected function restrictRequestQuery(Builder $query, User $user): Builder
    {
        return $this->accessScope->applyLeaveRequestScope($query, $user, AccessScopeService::REPORTS);
    }

    // ============================================================
    // 1. Leave Requests Report
    // ============================================================

    /**
     * Query approved leave requests with optional filters.
     *
     * @param  array{date_from?:?string, date_to?:?string, employee_id?:?string, department_id?:?string, zone_id?:?string, leave_type_id?:?string, status?:?string, policy_version?:?string}  $filters
     */
    public function leaveRequests(User $user, array $filters = []): Builder
    {
        $query = LeaveRequest::query()
            ->with(['employee', 'leaveType', 'policy'])
            ->where('status', self::APPROVED);

        $this->applyFilters($query, $filters);
        $this->restrictRequestQuery($query, $user);

        return $query->orderByDesc('start_date');
    }

    /**
     * Apply common filters to a LeaveRequest query.
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where('start_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('end_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }
        if (! empty($filters['zone_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('zone_id', $filters['zone_id']));
        }
        if (! empty($filters['leave_type_id'])) {
            $query->where('leave_type_id', $filters['leave_type_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['policy_version'])) {
            $query->where('policy_version', $filters['policy_version']);
        }
    }

    // ============================================================
    // 2. Employee Leave History Report
    // ============================================================

    /**
     * Query leave history for employees in scope.
     *
     * Includes all statuses (not just approved) for the employee's own history,
     * but only approved for others unless cross-zone.
     */
    public function employeeHistory(User $user, array $filters = []): Builder
    {
        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);
        $isCrossZone = $scope->canViewAllZones;

        $query = LeaveRequest::query()
            ->with(['employee', 'leaveType', 'policy'])
            ->when(! $isCrossZone, fn ($q) => $q->where('status', self::APPROVED));

        $this->applyFilters($query, $filters);
        $this->restrictRequestQuery($query, $user);

        return $query->orderBy('employee_id')->orderByDesc('start_date');
    }

    // ============================================================
    // 3. Leave Plans Report
    // ============================================================

    public function leavePlans(User $user, array $filters = []): Builder
    {
        $query = LeavePlan::query()
            ->with(['employee', 'leaveType', 'selectedOption']);

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }
        if (! empty($filters['zone_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('zone_id', $filters['zone_id']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);
        if (! $scope->canViewAllZones) {
            if (! $scope->hasZoneScope()) {
                $query->where('employee_id', $user->employee?->id);
            } else {
                $query->whereHas('employee', fn ($q) => $q->where(function ($sq) use ($scope) {
                    if ($scope->allowedZoneIds !== []) {
                        $sq->orWhereIn('zone_id', $scope->allowedZoneIds);
                    }
                    if ($scope->allowedDepartmentIds !== []) {
                        $sq->orWhereIn('department_id', $scope->allowedDepartmentIds);
                    }
                }));
            }
        }

        return $query->orderByDesc('created_at');
    }

    // ============================================================
    // 4. Waivers Report
    // ============================================================

    public function waivers(User $user, array $filters = []): Builder
    {
        $query = LeaveWaiver::query()
            ->with(['employee', 'leaveType']);

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['requested_action'])) {
            $query->where('requested_action', $filters['requested_action']);
        }

        $this->applyWaiverScope($query, $user);

        return $query->orderByDesc('created_at');
    }

    protected function applyWaiverScope(Builder $query, User $user): void
    {
        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);

        if ($scope->canViewAllZones) {
            return;
        }

        if (! $scope->hasZoneScope()) {
            $query->where('employee_id', $user->employee?->id);

            return;
        }

        $query->whereHas('employee', fn ($q) => $q->where(function ($sq) use ($scope) {
            if ($scope->allowedZoneIds !== []) {
                $sq->orWhereIn('zone_id', $scope->allowedZoneIds);
            }
            if ($scope->allowedDepartmentIds !== []) {
                $sq->orWhereIn('department_id', $scope->allowedDepartmentIds);
            }
        }));
    }

    // ============================================================
    // 5. Return / Late-Return Cases Report
    // ============================================================

    public function returns(User $user, array $filters = []): Builder
    {
        $query = LeaveReturn::query()
            ->with(['employee', 'leaveRequest.leaveType']);

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyReturnScope($query, $user);

        return $query->orderByDesc('created_at');
    }

    protected function applyReturnScope(Builder $query, User $user): void
    {
        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);

        if ($scope->canViewAllZones) {
            return;
        }

        if (! $scope->hasZoneScope()) {
            $query->where('employee_id', $user->employee?->id);

            return;
        }

        $query->whereHas('employee', fn ($q) => $q->where(function ($sq) use ($scope) {
            if ($scope->allowedZoneIds !== []) {
                $sq->orWhereIn('zone_id', $scope->allowedZoneIds);
            }
            if ($scope->allowedDepartmentIds !== []) {
                $sq->orWhereIn('department_id', $scope->allowedDepartmentIds);
            }
        }));
    }

    // ============================================================
    // 6. Dynamic Balances & Balance Transactions Report
    // ============================================================

    /**
     * Balance report showing dynamic approved usage, ledger credits,
     * ledger debits, and available balance per employee/leave type.
     *
     * Does NOT double-count approved requests and balance_transactions.
     * Usage = sum of approved leave_request_dates day_fraction.
     * Credits = balance_transactions with amount > 0.
     * Debits = balance_transactions with amount < 0 (absolute).
     * Available = credits - usage - debits.
     */
    public function balances(User $user, array $filters = []): Builder
    {
        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);

        $employeeQuery = Employee::query()->where('is_active', true);
        if ($scope->canViewAllZones) {
            // no restriction
        } elseif (! $scope->hasZoneScope()) {
            $employeeQuery->where('id', $user->employee?->id);
        } else {
            $employeeQuery->where(function ($q) use ($scope) {
                if ($scope->allowedZoneIds !== []) {
                    $q->orWhereIn('zone_id', $scope->allowedZoneIds);
                }
                if ($scope->allowedDepartmentIds !== []) {
                    $q->orWhereIn('department_id', $scope->allowedDepartmentIds);
                }
            });
        }

        if (! empty($filters['employee_id'])) {
            $employeeQuery->where('id', $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $employeeQuery->where('department_id', $filters['department_id']);
        }
        if (! empty($filters['zone_id'])) {
            $employeeQuery->where('zone_id', $filters['zone_id']);
        }
        if (! empty($filters['leave_type_id'])) {
            // filter later in the join
        }

        return $employeeQuery
            ->leftJoin('leave_types', function ($join) {
                $join->on('leave_types.id', '=', 'employees.leave_type_id'); // placeholder, will be overridden
            })
            ->select('employees.id', 'employees.full_name', 'employees.department_id', 'employees.zone_id');
    }

    /**
     * Get balance data as arrays for export (not a query builder).
     */
    public function getBalanceData(User $user, array $filters = []): array
    {
        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);

        $employees = Employee::query()->where('is_active', true);
        if (! $scope->canViewAllZones) {
            if (! $scope->hasZoneScope()) {
                $employees->where('id', $user->employee?->id);
            } else {
                $employees->where(function ($q) use ($scope) {
                    if ($scope->allowedZoneIds !== []) {
                        $q->orWhereIn('zone_id', $scope->allowedZoneIds);
                    }
                    if ($scope->allowedDepartmentIds !== []) {
                        $q->orWhereIn('department_id', $scope->allowedDepartmentIds);
                    }
                });
            }
        }
        if (! empty($filters['employee_id'])) {
            $employees->where('id', $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $employees->where('department_id', $filters['department_id']);
        }
        if (! empty($filters['zone_id'])) {
            $employees->where('zone_id', $filters['zone_id']);
        }

        $employees = $employees->get(['id', 'full_name', 'department_id', 'zone_id']);

        $leaveTypes = LeaveType::all()->keyBy('id');
        $dateFrom = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->endOfYear()->toDateString();

        $results = [];

        foreach ($employees as $employee) {
            foreach ($leaveTypes as $lt) {
                // Usage: sum of approved leave_request_dates day_fraction in range
                $usage = LeaveRequestDate::query()
                    ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_dates.request_id')
                    ->where('leave_requests.employee_id', $employee->id)
                    ->where('leave_requests.leave_type_id', $lt->id)
                    ->where('leave_requests.status', self::APPROVED)
                    ->whereBetween('leave_request_dates.the_date', [$dateFrom, $dateTo])
                    ->sum('day_fraction');

                // Credits: balance_transactions amount > 0
                $credits = BalanceTransaction::query()
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $lt->id)
                    ->where('amount', '>', 0)
                    ->whereBetween('created_at', [$dateFrom, $dateTo])
                    ->sum('amount');

                // Debits: balance_transactions amount < 0 (absolute)
                $debits = abs(BalanceTransaction::query()
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $lt->id)
                    ->where('amount', '<', 0)
                    ->whereBetween('created_at', [$dateFrom, $dateTo])
                    ->sum('amount'));

                $available = $credits - $usage - $debits;

                if ($usage > 0 || $credits > 0 || $debits > 0 || $available > 0) {
                    $results[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->full_name,
                        'department_id' => $employee->department_id,
                        'zone_id' => $employee->zone_id,
                        'leave_type_id' => $lt->id,
                        'leave_type_code' => $lt->code,
                        'leave_type_name' => $lt->name,
                        'usage_days' => round($usage, 1),
                        'ledger_credits' => round($credits, 1),
                        'ledger_debits' => round($debits, 1),
                        'available_days' => round($available, 1),
                    ];
                }
            }
        }

        return $results;
    }

    // ============================================================
    // 7. Payment Cases Report
    // ============================================================

    public function payments(User $user, array $filters = []): Builder
    {
        $query = LeavePaymentCase::query()
            ->with(['employee', 'leaveType', 'leaveRequest']);

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }
        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }
        if (! empty($filters['zone_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('zone_id', $filters['zone_id']));
        }

        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);
        if (! $scope->canViewAllZones) {
            if (! $scope->hasZoneScope()) {
                $query->where('employee_id', $user->employee?->id);
            } else {
                $query->whereHas('employee', fn ($q) => $q->where(function ($sq) use ($scope) {
                    if ($scope->allowedZoneIds !== []) {
                        $sq->orWhereIn('zone_id', $scope->allowedZoneIds);
                    }
                    if ($scope->allowedDepartmentIds !== []) {
                        $sq->orWhereIn('department_id', $scope->allowedDepartmentIds);
                    }
                }));
            }
        }

        return $query->orderByDesc('created_at');
    }

    // ============================================================
    // 8. Policies Report
    // ============================================================

    public function policies(User $user, array $filters = []): Builder
    {
        $query = LeavePolicy::query()
            ->with(['leaveType']);

        if (! empty($filters['date_from'])) {
            $query->where('effective_from', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('effective_to', '<=', $filters['date_to']);
        }
        if (! empty($filters['leave_type_id'])) {
            $query->where('leave_type_id', $filters['leave_type_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('leave_type_id')->orderByDesc('version');
    }

    // ============================================================
    // 9. Approval Performance Report
    // ============================================================

    public function approvalPerformance(User $user, array $filters = []): Builder
    {
        $query = ApprovalAction::query()
            ->with(['assignment.request.employee', 'assignment.request.leaveType', 'actor.employee'])
            ->join('workflow_assignments', 'workflow_assignments.id', '=', 'approval_actions.assignment_id')
            ->join('leave_requests', 'leave_requests.id', '=', 'workflow_assignments.request_id')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id');

        if (! empty($filters['date_from'])) {
            $query->where('approval_actions.acted_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('approval_actions.acted_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employees.id', $filters['employee_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('employees.department_id', $filters['department_id']);
        }
        if (! empty($filters['zone_id'])) {
            $query->where('employees.zone_id', $filters['zone_id']);
        }
        if (! empty($filters['decision'])) {
            $query->where('approval_actions.decision', $filters['decision']);
        }
        if (! empty($filters['role'])) {
            $query->where('workflow_assignments.assigned_role_id', $filters['role']);
        }

        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);
        if (! $scope->canViewAllZones) {
            if (! $scope->hasZoneScope()) {
                $query->where('employees.id', $user->employee?->id);
            } else {
                $query->where(function ($q) use ($scope) {
                    if ($scope->allowedZoneIds !== []) {
                        $q->orWhereIn('employees.zone_id', $scope->allowedZoneIds);
                    }
                    if ($scope->allowedDepartmentIds !== []) {
                        $q->orWhereIn('employees.department_id', $scope->allowedDepartmentIds);
                    }
                });
            }
        }

        return $query->select(
            'approval_actions.*',
            'workflow_assignments.assigned_role_id',
            'leave_requests.ref as request_ref',
            'leave_requests.leave_type_id',
            'employees.id as employee_id',
            'employees.full_name as employee_name',
            'employees.department_id',
            'employees.zone_id'
        )->orderByDesc('approval_actions.acted_at');
    }

    // ============================================================
    // 10. Documents Report
    // ============================================================

    public function documents(User $user, array $filters = []): Builder
    {
        $query = OfficialLeaveLetter::query()
            ->with(['leaveRequest.employee', 'leaveRequest.leaveType', 'generator']);

        if (! empty($filters['date_from'])) {
            $query->where('generated_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('generated_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['employee_id'])) {
            $query->whereHas('leaveRequest', fn ($q) => $q->where('employee_id', $filters['employee_id']));
        }
        if (! empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }
        if (! empty($filters['status'])) {
            $query->where(function ($q) use ($filters) {
                if ($filters['status'] === 'revoked') {
                    $q->whereNotNull('revoked_at');
                } else {
                    $q->whereNull('revoked_at');
                }
            });
        }

        $scope = $this->accessScope->forUser($user, AccessScopeService::REPORTS);
        if (! $scope->canViewAllZones) {
            if (! $scope->hasZoneScope()) {
                $query->whereHas('leaveRequest', fn ($q) => $q->where('employee_id', $user->employee?->id));
            } else {
                $query->whereHas('leaveRequest.employee', fn ($q) => $q->where(function ($sq) use ($scope) {
                    if ($scope->allowedZoneIds !== []) {
                        $sq->orWhereIn('zone_id', $scope->allowedZoneIds);
                    }
                    if ($scope->allowedDepartmentIds !== []) {
                        $sq->orWhereIn('department_id', $scope->allowedDepartmentIds);
                    }
                }));
            }
        }

        return $query->orderByDesc('generated_at');
    }

    // ============================================================
    // 11. Audit History Report
    // ============================================================

    public function audit(User $user, array $filters = []): Builder
    {
        $query = AuditLog::query()->with('actor.employee');

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', $filters['actor_user_id']);
        }

        // Audit logs are readable by anyone with audit permission,
        // but scoped to what they can see (no scope restriction by default for admin).

        return $query->orderByDesc('id');
    }
}
