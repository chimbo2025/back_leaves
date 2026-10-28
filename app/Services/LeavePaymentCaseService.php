<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\PolicyRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave-payment case lifecycle (Phase 5).
 *
 * Five concerns are kept apart on purpose and this service is where they are
 * joined without being merged:
 *
 *   1. the leave request's own approval (already decided, read-only here),
 *   2. payment eligibility (LeavePaymentEligibilityService),
 *   3. leave-purchase terms (LeavePurchaseService),
 *   4. the money calculation (LeavePaymentCalculationService),
 *   5. the Accounting settlement (AccountingNotificationService + the
 *      receive/process transitions).
 *
 * Every transition runs inside a transaction, locks the case row, is idempotent
 * (repeating it is a no-op, not a second write) and writes one audit event. A
 * paid case is terminal and no transition method will touch it.
 *
 * Two authorisation rules are enforced here rather than in the route layer,
 * because they are about *who*, not *what*:
 *   - no self-approval: the person who opened or submitted a case (or is the
 *     subject of it) can never approve, reject or settle it;
 *   - scope: a reviewer must be able to see the subject's zone/department,
 *     unless they hold the cross-zone code.
 */
class LeavePaymentCaseService
{
    public function __construct(
        protected LeavePolicyResolver $resolver,
        protected LeavePaymentEligibilityService $eligibility,
        protected LeavePaymentCalculationService $calculator,
        protected AccountingNotificationService $accounting,
        protected AccessScopeService $scope,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    /**
     * Open a payment case, evaluate it and calculate it in one controlled step.
     *
     * @param  array{employee_id:string,leave_type_id:string,leave_request_id?:?int,payment_type?:string,salary_basis?:?string,salary_amount?:float|int|string|null,allowances_included?:array<int,mixed>,allowances_excluded?:array<int,mixed>,days?:float|int|null,eligible_days?:float|int|null,currency?:?string,loan_outstanding?:bool,idempotency_key?:?string}  $data
     */
    public function create(User $actor, array $data): LeavePaymentCase
    {
        $actorEmployee = $actor->employee;

        if (! $actorEmployee) {
            throw new AuthorizationException('A payment case can only be opened by an employee.');
        }

        return DB::transaction(function () use ($actor, $actorEmployee, $data) {
            if (! empty($data['idempotency_key'])) {
                $existing = LeavePaymentCase::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $employee = Employee::findOrFail($data['employee_id']);
            $leaveType = LeaveType::findOrFail($data['leave_type_id']);
            $paymentType = $data['payment_type'] ?? LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT;

            if (! in_array($paymentType, LeavePaymentCase::PAYMENT_TYPES, true)) {
                throw ValidationException::withMessages([
                    'payment_type' => "Unsupported payment type: {$paymentType}.",
                ]);
            }

            $request = isset($data['leave_request_id'])
                ? LeaveRequest::find($data['leave_request_id'])
                : null;

            if ($request && $request->employee_id !== $employee->id) {
                throw ValidationException::withMessages([
                    'leave_request_id' => 'The leave request does not belong to this employee.',
                ]);
            }

            // Duplicates are per employee: without the employee constraint a
            // live encashment for one officer blocked every other officer's
            // same-type case, which made office-wide payment reporting
            // impossible (two settlements of the same type could never
            // co-exist). Same-employee duplicates are still refused.
            $duplicate = LeavePaymentCase::query()
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->when($request, fn ($q) => $q->where('leave_request_id', $request->id))
                ->when(! $request, fn ($q) => $q->whereNull('leave_request_id'))
                ->where('payment_type', $paymentType)
                ->whereNotIn('status', [
                    LeavePaymentCase::STATUS_CANCELLED,
                    LeavePaymentCase::STATUS_HR_REJECTED,
                    LeavePaymentCase::STATUS_PAYMENT_REJECTED,
                ])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'leave_request_id' => 'A payment case already exists for this leave and payment type.',
                ]);
            }

            $resolved = $this->resolver->resolveWithRules($leaveType->id, $request?->start_date?->toDateString());

            $case = LeavePaymentCase::create([
                'employee_id' => $employee->id,
                'leave_request_id' => $request?->id,
                'leave_type_id' => $leaveType->id,
                'policy_id' => $resolved['policy_id'],
                'payment_type' => $paymentType,
                'status' => LeavePaymentCase::STATUS_PENDING,
                'salary_basis' => $data['salary_basis'] ?? null,
                'salary_amount' => isset($data['salary_amount']) ? (float) $data['salary_amount'] : null,
                'allowances_included' => $data['allowances_included'] ?? [],
                'allowances_excluded' => $data['allowances_excluded'] ?? [],
                'currency' => $data['currency'] ?? config('leave.payments.currency', 'TZS'),
                'created_by' => $actorEmployee->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            $this->audit->append('leave_payment_case', (string) $case->id, 'created', $actor->id, [
                'employee_id' => $case->employee_id,
                'leave_request_id' => $case->leave_request_id,
                'leave_type_id' => $case->leave_type_id,
                'payment_type' => $case->payment_type,
                'status' => $case->status,
            ]);

            $this->refreshEvaluation($actor, $case, $data);

            return $case->fresh();
        });
    }

    /** Re-run eligibility and calculation on a case that is still open. */
    public function evaluate(User $actor, LeavePaymentCase $case, array $data = []): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $data) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status} payment case can no longer be evaluated.",
                ]);
            }

            $this->refreshEvaluation($actor, $locked, $data);

            return $locked->fresh();
        });
    }

    public function submitForHrReview(User $actor, LeavePaymentCase $case, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeavePaymentCase::STATUS_UNDER_HR_REVIEW) {
                return $locked->fresh();
            }

            if ($locked->status !== LeavePaymentCase::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => "Only a pending case can be submitted for review (current: {$locked->status}).",
                ]);
            }

            $this->assertEligible($locked);

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_UNDER_HR_REVIEW,
                'submitted_by' => $actorEmployee->id,
                'submitted_at' => now(),
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'submitted_for_review', $actor->id, [
                'status' => $locked->status,
                'calculated_amount' => $locked->calculated_amount,
            ], reason: $comment);

            return $locked->fresh();
        });
    }

    public function approve(User $actor, LeavePaymentCase $case, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeavePaymentCase::STATUS_HR_APPROVED) {
                return $locked->fresh();
            }

            if ($locked->status !== LeavePaymentCase::STATUS_UNDER_HR_REVIEW) {
                throw ValidationException::withMessages([
                    'status' => "Only a case under HR review can be approved (current: {$locked->status}).",
                ]);
            }

            $this->assertNotSelf($actor, $locked, 'approve');
            $this->assertScope($actor, $locked);
            $this->assertEligible($locked);

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_HR_APPROVED,
                'hr_reviewed_by' => $actorEmployee->id,
                'hr_reviewed_at' => now(),
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'hr_approved', $actor->id, [
                'status' => $locked->status,
                'calculated_amount' => $locked->calculated_amount,
            ], reason: $comment);

            return $locked->fresh();
        });
    }

    public function reject(User $actor, LeavePaymentCase $case, string $reason, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $reason, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeavePaymentCase::STATUS_HR_REJECTED) {
                return $locked->fresh();
            }

            if ($locked->status !== LeavePaymentCase::STATUS_UNDER_HR_REVIEW) {
                throw ValidationException::withMessages([
                    'status' => "Only a case under HR review can be rejected (current: {$locked->status}).",
                ]);
            }

            $this->assertNotSelf($actor, $locked, 'reject');
            $this->assertScope($actor, $locked);

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_HR_REJECTED,
                'hr_reviewed_by' => $actorEmployee->id,
                'hr_reviewed_at' => now(),
                'rejected_by' => $actorEmployee->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'hr_rejected', $actor->id, [
                'status' => $locked->status,
                'reason' => $reason,
            ], reason: $comment ?? $reason);

            return $locked->fresh();
        });
    }

    public function sendToAccounting(User $actor, LeavePaymentCase $case, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [
                LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING,
                LeavePaymentCase::STATUS_PROCESSING,
                LeavePaymentCase::STATUS_PAID,
            ], true)) {
                return $locked->fresh();
            }

            if ($locked->status !== LeavePaymentCase::STATUS_HR_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => "Only an HR-approved case can be sent to Accounting (current: {$locked->status}).",
                ]);
            }

            $this->assertScope($actor, $locked);
            $this->assertEligible($locked);

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING,
                'sent_to_accounting_by' => $actorEmployee->id,
                'sent_to_accounting_at' => now(),
                'accounting_notified_at' => now(),
            ]);

            $sent = $this->accounting->notify($locked);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'sent_to_accounting', $actor->id, [
                'status' => $locked->status,
                'calculated_amount' => $locked->calculated_amount,
                'recipients' => $sent,
            ], reason: $comment);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'accounting_notified', $actor->id, [
                'recipients' => $sent,
            ]);

            return $locked->fresh();
        });
    }

    public function receive(User $actor, LeavePaymentCase $case, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [LeavePaymentCase::STATUS_PROCESSING, LeavePaymentCase::STATUS_PAID], true)) {
                return $locked->fresh();
            }

            if ($locked->status !== LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING) {
                throw ValidationException::withMessages([
                    'status' => "Only a case sent to Accounting can be received (current: {$locked->status}).",
                ]);
            }

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_PROCESSING,
                'processing_at' => now(),
                'processed_by' => $actorEmployee->id,
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'accounting_received', $actor->id, [
                'status' => $locked->status,
            ], reason: $comment);

            return $locked->fresh();
        });
    }

    public function markPaid(User $actor, LeavePaymentCase $case, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeavePaymentCase::STATUS_PAID) {
                return $locked->fresh();
            }

            if ($locked->status !== LeavePaymentCase::STATUS_PROCESSING) {
                throw ValidationException::withMessages([
                    'status' => "Only a case being processed can be marked paid (current: {$locked->status}).",
                ]);
            }

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_PAID,
                'paid_at' => now(),
                'processed_by' => $locked->processed_by ?? $actorEmployee->id,
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'paid', $actor->id, [
                'status' => $locked->status,
                'calculated_amount' => $locked->calculated_amount,
                'currency' => $locked->currency,
            ], reason: $comment);

            return $locked->fresh();
        });
    }

    public function rejectPayment(User $actor, LeavePaymentCase $case, string $reason, ?string $comment = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $reason, $comment) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeavePaymentCase::STATUS_PAYMENT_REJECTED) {
                return $locked->fresh();
            }

            if (! in_array($locked->status, [LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING, LeavePaymentCase::STATUS_PROCESSING], true)) {
                throw ValidationException::withMessages([
                    'status' => "Only a case with Accounting can have its payment rejected (current: {$locked->status}).",
                ]);
            }

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_PAYMENT_REJECTED,
                'processed_by' => $actorEmployee->id,
                'rejected_by' => $actorEmployee->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'payment_rejected', $actor->id, [
                'status' => $locked->status,
                'reason' => $reason,
            ], reason: $comment ?? $reason);

            return $locked->fresh();
        });
    }

    public function cancel(User $actor, LeavePaymentCase $case, ?string $reason = null): LeavePaymentCase
    {
        return DB::transaction(function () use ($actor, $case, $reason) {
            $locked = LeavePaymentCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeavePaymentCase::STATUS_CANCELLED) {
                return $locked->fresh();
            }

            if (! in_array($locked->status, LeavePaymentCase::CANCELLABLE, true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status} payment case cannot be cancelled.",
                ]);
            }

            $this->assertScope($actor, $locked);

            $actorEmployee = $this->employeeOrFail($actor);

            $locked->update([
                'status' => LeavePaymentCase::STATUS_CANCELLED,
                'cancelled_by' => $actorEmployee->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->audit->append('leave_payment_case', (string) $locked->id, 'cancelled', $actor->id, [
                'status' => $locked->status,
            ], reason: $reason);

            return $locked->fresh();
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Re-evaluate eligibility and recalculate, persisting both snapshots.
     * Used by create() and evaluate(); the case must already exist so the
     * duplicate check can exclude it.
     *
     * @param  array<string,mixed>  $data
     */
    protected function refreshEvaluation(User $actor, LeavePaymentCase $case, array $data = []): void
    {
        $employee = Employee::findOrFail($case->employee_id);
        $leaveType = LeaveType::findOrFail($case->leave_type_id);
        $request = $case->leave_request_id ? LeaveRequest::find($case->leave_request_id) : null;

        $eligibility = $this->eligibility->evaluate($employee, $leaveType, [
            'payment_type' => $case->payment_type,
            'leave_request' => $request,
            'days' => $data['days'] ?? null,
            'salary_basis' => $case->salary_basis,
            'salary_amount' => $case->salary_amount !== null ? (float) $case->salary_amount : null,
            'loan_outstanding' => (bool) ($data['loan_outstanding'] ?? false),
            'exclude_case_id' => $case->id,
            'date' => $request?->start_date?->toDateString(),
        ]);

        $case->update([
            'policy_id' => $eligibility['policy_id'] ?? $case->policy_id,
            'eligibility_snapshot' => $eligibility['snapshot'],
        ]);

        $this->audit->append('leave_payment_case', (string) $case->id, 'eligibility_evaluated', $actor->id, [
            'ok' => $eligibility['ok'],
            'blockers' => $eligibility['blockers'],
            'policy_id' => $eligibility['policy_id'],
            'policy_version' => $eligibility['policy_version'],
        ]);

        $resolved = $this->resolver->resolveWithRules($leaveType->id, $request?->start_date?->toDateString());
        $divisor = PolicyRules::number(
            $resolved['resolved_rules'] ?? [],
            'payment.day_rate_divisor',
            (float) config('leave.payments.day_rate_divisor', 30),
        );

        if ($case->salary_amount === null || (float) $case->salary_amount <= 0) {
            $case->update(['calculated_amount' => null, 'eligible_amount' => null]);

            return;
        }

        $days = $data['days'] ?? ($request?->days !== null ? (float) $request->days : null);
        if ($days === null || $days <= 0) {
            $case->update(['calculated_amount' => null, 'eligible_amount' => null]);

            return;
        }

        $calculation = $this->calculator->calculate([
            'salary_basis' => $case->salary_basis,
            'salary_amount' => (float) $case->salary_amount,
            'allowances_included' => $case->allowances_included ?? [],
            'allowances_excluded' => $case->allowances_excluded ?? [],
            'days' => $days,
            'eligible_days' => $data['eligible_days'] ?? $days,
            'divisor' => $divisor,
            'currency' => $case->currency,
            'policy_id' => $eligibility['policy_id'] ?? $case->policy_id,
            'policy_version' => $eligibility['policy_version'] ?? null,
        ]);

        $case->update([
            'eligible_amount' => $calculation['eligible_amount'],
            'calculated_amount' => $calculation['calculated_amount'],
            'calculation_snapshot' => $calculation['snapshot'],
        ]);

        $this->audit->append('leave_payment_case', (string) $case->id, 'calculated', $actor->id, [
            'calculated_amount' => $calculation['calculated_amount'],
            'currency' => $calculation['currency'],
        ]);
    }

    protected function assertEligible(LeavePaymentCase $case): void
    {
        $snapshot = $case->eligibility_snapshot ?? [];
        $blockers = $snapshot['blockers'] ?? null;

        if ($blockers === null) {
            throw ValidationException::withMessages([
                'eligibility' => 'This case has not been evaluated for eligibility.',
            ]);
        }

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'eligibility' => 'This case is not eligible for payment: '.implode(' ', $blockers),
            ]);
        }

        if ($case->calculated_amount === null) {
            throw ValidationException::withMessages([
                'calculated_amount' => 'This case has no calculated amount.',
            ]);
        }
    }

    protected function assertNotSelf(User $actor, LeavePaymentCase $case, string $action): void
    {
        $employeeId = $actor->employee?->id;

        if ($employeeId === null) {
            throw new AuthorizationException("A payment case can only be {$action}d by an employee.");
        }

        if (in_array($employeeId, [$case->employee_id, $case->created_by, $case->submitted_by], true)) {
            throw ValidationException::withMessages([
                'authorization' => "You cannot {$action} a payment case you opened, submitted or are the subject of.",
            ]);
        }
    }

    protected function assertScope(User $actor, LeavePaymentCase $case): void
    {
        $scope = $this->scope->forUser($actor, 'leave_payments');

        if ($scope->canViewAllZones) {
            return;
        }

        $employee = $case->employee;

        if (! $employee || ! $scope->hasZoneScope()
            || (! in_array($employee->zone_id, $scope->allowedZoneIds, true)
                && ! in_array($employee->department_id, $scope->allowedDepartmentIds, true))) {
            throw new AuthorizationException('You do not have scope over this payment case.');
        }
    }

    protected function employeeOrFail(User $actor): Employee
    {
        $employee = $actor->employee;

        if (! $employee) {
            throw new AuthorizationException('A payment case can only be acted on by an employee.');
        }

        return $employee;
    }
}
