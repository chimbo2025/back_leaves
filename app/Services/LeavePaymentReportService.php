<?php

namespace App\Services;

use App\Http\Resources\PaymentCaseAccountingResource;
use App\Http\Resources\PaymentCaseAdminResource;
use App\Http\Resources\PaymentCaseEmployeeResource;
use App\Http\Resources\PaymentCaseHRResource;
use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payment visibility reporting.
 *
 * Audience model (enforced on every method that returns rows):
 * - admin:      holds FINAL_AUTHORITY or BREAK_GLASS → AdminResource, all rows.
 * - hr:         holds leave_payments review/approve → HRResource, scope-bounded.
 * - accounting: holds receive/process but NOT review/approve → AccountingResource,
 *               and only cases with status sent_to_accounting/processing/paid,
 *               whatever the endpoint. Accountants settle money; they never see
 *               eligibility reasoning, salary, leave types, reasons or comments.
 * - employee:   anyone else → EmployeeResource, own cases only (used by the
 *               per-employee history endpoint; category endpoints deny them).
 *
 * Payment visibility rules enforced here (mirroring LeavePaymentController):
 * - Accounting cannot approve leave, HR eligibility, or see medical details,
 *   private leave reasons or internal HR comments: it never holds those
 *   codes, and its resource carries none of those fields.
 * - HR cannot mark payments paid: the paid transition needs
 *   leave_payments.process, which HR does not hold.
 * - Final authority needs its explicit grants like everyone else: no
 *   wildcard, no role-name checks anywhere in this file.
 */
class LeavePaymentReportService
{
    public const AUDIENCE_ADMIN = 'admin';

    public const AUDIENCE_HR = 'hr';

    public const AUDIENCE_ACCOUNTING = 'accounting';

    public const AUDIENCE_EMPLOYEE = 'employee';

    /** Statuses an Accounting audience may ever see, on any endpoint. */
    public const ACCOUNTING_STATUSES = [
        LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING,
        LeavePaymentCase::STATUS_PROCESSING,
        LeavePaymentCase::STATUS_PAID,
    ];

    public function __construct(
        protected PermissionService $permissions,
        protected AccessScopeService $accessScope,
    ) {}

    public function audienceFor(User $user): string
    {
        $roleIds = $this->permissions->activeAssignments($user->employee?->id ?? '')
            ->pluck('role_id')->all();

        if (array_intersect($roleIds, ['FINAL_AUTHORITY', 'BREAK_GLASS']) !== []) {
            return self::AUDIENCE_ADMIN;
        }

        if ($this->permissions->can($user, 'leave_payments', 'review')
            || $this->permissions->can($user, 'leave_payments', 'approve')) {
            return self::AUDIENCE_HR;
        }

        if ($this->permissions->can($user, 'leave_payments', 'receive')
            || $this->permissions->can($user, 'leave_payments', 'process')) {
            return self::AUDIENCE_ACCOUNTING;
        }

        return self::AUDIENCE_EMPLOYEE;
    }

    /** @return class-string<JsonResource> */
    public function resourceFor(User $user): string
    {
        return match ($this->audienceFor($user)) {
            self::AUDIENCE_ADMIN => PaymentCaseAdminResource::class,
            self::AUDIENCE_HR => PaymentCaseHRResource::class,
            self::AUDIENCE_ACCOUNTING => PaymentCaseAccountingResource::class,
            default => PaymentCaseEmployeeResource::class,
        };
    }

    /**
     * Shared case base: filters + role subset + scope. Category builders and
     * the summary all start here, so totals and rows share one definition.
     */
    public function baseCases(User $user, LeaveReportFilters $filters): Builder
    {
        $query = LeavePaymentCase::query()
            ->with(['employee.department', 'employee.zone', 'leaveType', 'leaveRequest']);

        $filters->applyCreatedOverlap($query);

        if (! empty($filters->get('payment_status'))) {
            $query->where('status', $filters->get('payment_status'));
        }

        if (! empty($filters->get('payment_type'))) {
            $query->where('payment_type', $filters->get('payment_type'));
        }

        if (! empty($filters->get('employee_id'))) {
            $query->where('employee_id', $filters->get('employee_id'));
        }

        if (! empty($filters->get('leave_type_id'))) {
            $query->where('leave_type_id', $filters->get('leave_type_id'));
        }

        if (! empty($filters->get('department_id'))) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $filters->get('department_id')));
        }

        if (! empty($filters->get('zone_id'))) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('zone_id', $filters->get('zone_id')));
        }

        if (! empty($filters->get('search'))) {
            $search = '%'.str_replace(['%', '_'], '', (string) $filters->get('search')).'%';
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('employee', fn (Builder $e) => $e->where('full_name', 'like', $search))
                    ->orWhereHas('leaveRequest', fn (Builder $r) => $r->where('ref', 'like', $search));
            });
        }

        // Accounting subset first: even an explicit status filter cannot widen
        // an accountant past settlement-visible cases.
        if ($this->audienceFor($user) === self::AUDIENCE_ACCOUNTING) {
            $query->whereIn('status', self::ACCOUNTING_STATUSES);
        }

        // Same scope rule as LeavePaymentController@index: own rows, else
        // zone/department cover, unless cross-zone.
        $scope = $this->accessScope->forUser($user, 'leave_payments');

        if (! $scope->canViewAllZones) {
            $employeeId = $user->employee?->id;
            if (! $scope->hasZoneScope()) {
                $query->where('employee_id', $employeeId);
            } else {
                $query->where(function (Builder $q) use ($scope, $employeeId) {
                    $q->whereHas('employee', function (Builder $e) use ($scope) {
                        $e->where(function (Builder $inner) use ($scope) {
                            if ($scope->allowedZoneIds !== []) {
                                $inner->orWhereIn('zone_id', $scope->allowedZoneIds);
                            }
                            if ($scope->allowedDepartmentIds !== []) {
                                $inner->orWhereIn('department_id', $scope->allowedDepartmentIds);
                            }
                        });
                    });
                    if ($employeeId !== null) {
                        $q->orWhere('employee_id', $employeeId);
                    }
                });
            }
        }

        return $query;
    }

    /** @return array<string,callable(User,LeaveReportFilters):Builder> */
    public function caseCategories(): array
    {
        return [
            'paid' => fn (User $u, LeaveReportFilters $f) => $this->baseCases($u, $f)->where('status', LeavePaymentCase::STATUS_PAID),
            'pending_hr' => fn (User $u, LeaveReportFilters $f) => $this->baseCases($u, $f)->whereIn('status', [LeavePaymentCase::STATUS_PENDING, LeavePaymentCase::STATUS_UNDER_HR_REVIEW]),
            'pending_accounting' => fn (User $u, LeaveReportFilters $f) => $this->baseCases($u, $f)->whereIn('status', [LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING, LeavePaymentCase::STATUS_PROCESSING]),
            'processing' => fn (User $u, LeaveReportFilters $f) => $this->baseCases($u, $f)->where('status', LeavePaymentCase::STATUS_PROCESSING),
            'rejected' => fn (User $u, LeaveReportFilters $f) => $this->baseCases($u, $f)->whereIn('status', [LeavePaymentCase::STATUS_HR_REJECTED, LeavePaymentCase::STATUS_PAYMENT_REJECTED]),
            'unpaid' => fn (User $u, LeaveReportFilters $f) => $this->baseCases($u, $f)->where('status', '!=', LeavePaymentCase::STATUS_PAID),
        ];
    }

    /**
     * Display rows for one page of pair-member keys. Only the slice's rows
     * are loaded (employees, types, linked requests, cases, eager); the
     * totals come from member-key counts, never from loaded rows. The same
     * report windows the analysis used apply here, so the displayed linked
     * rows are the ones the bucket was computed from.
     *
     * @param  array<int,array{employee_id:string,leave_type_id:string}>  $keys
     * @return array<int,array<string,mixed>>
     */
    public function pairRows(array $keys, User $user, ?LeaveReportFilters $filters = null): array
    {
        if ($keys === []) {
            return [];
        }

        $audience = $this->audienceFor($user);
        $resource = $this->resourceFor($user);

        $employeeIds = array_values(array_unique(array_column($keys, 'employee_id')));
        $employees = Employee::query()
            ->with(['department', 'zone'])
            ->whereKey($employeeIds)
            ->get()->keyBy('id');

        $typeIds = array_values(array_unique(array_column($keys, 'leave_type_id')));
        $types = LeaveType::query()->whereKey($typeIds)->get()->keyBy('id');

        // Linked rows, narrow selects only, same windows as the analysis.
        $requestQuery = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('leave_type_id', $typeIds)
            ->where('status', 'approved')
            ->select(['id', 'employee_id', 'leave_type_id', 'ref', 'status', 'start_date', 'end_date', 'days']);
        $caseQuery = LeavePaymentCase::query()
            ->with(['employee.department', 'employee.zone', 'leaveType', 'leaveRequest'])
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('leave_type_id', $typeIds)
            ->orderBy('id');

        if ($filters !== null) {
            $filters->applyLeaveOverlap($requestQuery);
            $filters->applyCreatedOverlap($caseQuery);
        }

        $requests = $requestQuery->get()
            ->groupBy(fn ($r) => $r->employee_id.'|'.$r->leave_type_id)
            ->map(fn ($g) => $g->sortByDesc('id')->first());

        $cases = $caseQuery->get()
            ->groupBy(fn ($c) => $c->employee_id.'|'.$c->leave_type_id)
            ->map(fn ($g) => $g->last());

        $rows = [];
        foreach ($keys as $key) {
            $pairKey = $key['employee_id'].'|'.$key['leave_type_id'];
            $employee = $employees->get($key['employee_id']);
            $type = $types->get($key['leave_type_id']);

            if (! $employee || ! $type) {
                continue;
            }

            $req = $requests->get($pairKey);
            $case = $cases->get($pairKey);

            $rows[] = [
                'employee' => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'department' => $employee->department ? [
                        'id' => $employee->department->id,
                        'name' => $employee->department->name,
                    ] : null,
                    'zone' => $employee->zone ? [
                        'id' => $employee->zone->id,
                        'name' => $employee->zone->name,
                        'short' => $employee->zone->short,
                    ] : null,
                ],
                // Accounting never learns the leave type: a code or name
                // like MATERNITY is medical information.
                'leave_type' => $audience === self::AUDIENCE_ACCOUNTING
                    ? ['id' => $type->id]
                    : ['id' => $type->id, 'code' => $type->code, 'name' => $type->name],
                'leave_request' => $req ? [
                    'id' => $req->id,
                    'ref' => $req->ref,
                    'status' => $req->status,
                    'start_date' => $req->start_date?->toDateString(),
                    'end_date' => $req->end_date?->toDateString(),
                    'days' => $req->days !== null ? (float) $req->days : null,
                ] : null,
                'payment_case' => $case ? $resource::make($case)->toArray(request()) : null,
                'eligibility' => 'eligible',
            ];
        }

        return $rows;
    }

    /**
     * One employee's case history. Owners always see their own; anyone else
     * needs report-level access plus scope covering that employee.
     */
    public function historyQuery(User $user, string $employeeId, LeaveReportFilters $filters): Builder
    {
        $query = $this->baseCases($user, $filters)->where('employee_id', $employeeId);

        // baseCases already scopes; when the caller is not the owner and has
        // no scope cover, the scope clause yields zero rows by itself.
        return $query;
    }

    public function mayViewHistory(User $user, string $employeeId): bool
    {
        if ($user->employee?->id !== null && $user->employee->id === $employeeId) {
            return true;
        }

        if (! $this->canViewPaymentReports($user)) {
            return false;
        }

        $scope = $this->accessScope->forUser($user, 'leave_payments');

        if ($scope->canViewAllZones) {
            return true;
        }

        $employee = Employee::query()->find($employeeId);

        if (! $employee) {
            return false;
        }

        return in_array($employee->zone_id, $scope->allowedZoneIds, true)
            || in_array($employee->department_id, $scope->allowedDepartmentIds, true);
    }

    /**
     * Report-level gate for the payment overview surface: financial reviewers
     * (HR-side money visibility) or cross-zone payment holders (Accounting).
     * Employees reach only their own history, which is gated separately.
     */
    public function canViewPaymentReports(User $user): bool
    {
        return $this->permissions->can($user, 'reports', 'view_financial')
            || $this->permissions->can($user, 'leave_payments', 'view_cross_zone');
    }
}
