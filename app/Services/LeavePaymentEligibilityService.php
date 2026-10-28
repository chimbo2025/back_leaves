<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\PolicyRules;

/**
 * Leave-payment eligibility (Phase 5).
 *
 * Answers "should this person be paid for this leave?" independently of both
 * the leave approval and the money calculation. The leave request already
 * decided whether the leave is allowed; this decides whether a payment may
 * arise from it, and it does so by reading the resolved policy rather than by
 * re-using a rule of thumb.
 *
 * The result is returned as a snapshot and stored on the case, so the reasons a
 * payment was or was not eligible are frozen at the moment of the decision. A
 * later policy revision must not be able to rewrite history.
 *
 * Each check is recorded, not just the failures, so the audit trail shows what
 * was tested and passed as well as what blocked.
 */
class LeavePaymentEligibilityService
{
    public const RULE_PAYMENT_ENABLED = 'payment.enabled';

    public const RULE_ALLOW_UNPAID = 'payment.allow_unpaid';

    public const RULE_ALLOW_STATUTORY = 'payment.allow_statutory';

    public const RULE_BLOCK_ON_LOAN = 'payment.block_on_outstanding_loan';

    public function __construct(
        protected LeavePolicyResolver $resolver,
        protected LeavePurchaseService $purchase,
    ) {}

    /**
     * @param  array{payment_type?:string,leave_request?:?LeaveRequest,days?:?float,salary_basis?:?string,salary_amount?:?float,loan_outstanding?:bool,exclude_case_id?:?int,date?:?string}  $options
     * @return array{ok:bool,blockers:array<int,string>,policy_id:?string,policy_version:?int,source:?string,snapshot:array<string,mixed>}
     */
    public function evaluate(Employee $employee, LeaveType $leaveType, array $options = []): array
    {
        $paymentType = $options['payment_type'] ?? LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT;
        $request = $options['leave_request'] ?? null;
        $days = $options['days'] ?? ($request?->days !== null ? (float) $request->days : null);
        $salaryBasis = $options['salary_basis'] ?? null;
        $salaryAmount = isset($options['salary_amount']) ? (float) $options['salary_amount'] : null;
        $loanOutstanding = (bool) ($options['loan_outstanding'] ?? false);
        $excludeCaseId = $options['exclude_case_id'] ?? null;

        $resolved = $this->resolver->resolveWithRules($leaveType->id, $options['date'] ?? null);
        $policy = $resolved['policy'];
        $rules = $resolved['resolved_rules'] ?? [];

        $checks = [];
        $blockers = [];

        $add = function (string $code, bool $ok, string $message) use (&$checks, &$blockers): void {
            $checks[] = ['code' => $code, 'ok' => $ok, 'message' => $message];
            if (! $ok) {
                $blockers[] = $message;
            }
        };

        // 1. employee status
        $add(
            'employee_active',
            (bool) $employee->is_active,
            $employee->is_active ? 'Employee is active.' : 'Employee is not active.',
        );

        // 2. payment type must be one we know
        $add(
            'payment_type_supported',
            in_array($paymentType, LeavePaymentCase::PAYMENT_TYPES, true),
            in_array($paymentType, LeavePaymentCase::PAYMENT_TYPES, true)
                ? "Payment type {$paymentType} is supported."
                : "Payment type {$paymentType} is not supported.",
        );

        // 3. policy must resolve
        $add(
            'policy_resolved',
            $policy !== null,
            $policy ? 'A policy applies to this leave type.' : 'No active policy could be resolved for this leave type.',
        );

        // 4. policy-level switch
        $paymentEnabled = PolicyRules::enabled($rules, self::RULE_PAYMENT_ENABLED, true);
        $add(
            'payment_enabled',
            $paymentEnabled,
            $paymentEnabled ? 'Payment is enabled by policy.' : 'Payments are disabled by policy.',
        );

        // 5. the leave request, when there is one, must be approved
        if ($request instanceof LeaveRequest) {
            $approved = $request->status === 'approved';
            $add(
                'leave_request_approved',
                $approved,
                $approved ? 'The underlying leave request is approved.' : "The underlying leave request is {$request->status}, not approved.",
            );
        }

        // 6. a concrete day count is required
        $hasDays = $days !== null && $days > 0;
        $add(
            'days_present',
            $hasDays,
            $hasDays ? "{$this->trim((float) $days)} day(s) are in scope." : 'A positive number of days is required.',
        );

        if ($paymentType === LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT) {
            // 7. unpaid leave types cannot be encashed unless policy says so
            $allowUnpaid = PolicyRules::enabled($rules, self::RULE_ALLOW_UNPAID, false);
            $isPaidType = (bool) $leaveType->is_paid_default;
            $add(
                'leave_type_paid',
                $isPaidType || $allowUnpaid,
                $isPaidType || $allowUnpaid
                    ? 'The leave type is a paid entitlement.'
                    : 'The leave type is unpaid and cannot be encashed.',
            );

            // 8. statutory maternity/paternity are excluded from encashment
            $statutory = in_array($leaveType->code, ['MATERNITY', 'PATERNITY'], true);
            $allowStatutory = PolicyRules::enabled($rules, self::RULE_ALLOW_STATUTORY, false);
            $add(
                'statutory_excluded',
                ! $statutory || $allowStatutory,
                ! $statutory || $allowStatutory
                    ? 'No statutory encashment restriction applies.'
                    : 'Statutory maternity/paternity leave cannot be encashed.',
            );
        }

        // 9. purchase rules (a no-op unless the payment type is a purchase)
        $purchase = $this->purchase->check($employee, $leaveType, $policy, $rules, $hasDays ? $days : null, $paymentType);
        foreach ($purchase['blockers'] as $message) {
            $add('leave_purchase', false, $message);
        }
        if ($purchase['blockers'] === []) {
            $add('leave_purchase', true, 'Leave purchase rules are satisfied.');
        }

        // 10. a salary basis must be supplied (there is no payroll table to read)
        $hasSalary = $salaryAmount !== null && $salaryAmount > 0;
        $add(
            'salary_basis_present',
            $hasSalary,
            $hasSalary ? 'A salary basis amount is present.' : 'A salary basis amount is required.',
        );

        // 11. no prior payment for the same leave. Scoped to the employee:
        // without it, one officer's settled encashment would block every
        // other officer's same-type payment as "already paid".
        $alreadyPaid = LeavePaymentCase::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->when($request, fn ($q) => $q->where('leave_request_id', $request->id))
            ->when(! $request, fn ($q) => $q->whereNull('leave_request_id'))
            ->where('payment_type', $paymentType)
            ->where('status', LeavePaymentCase::STATUS_PAID)
            ->exists();
        $add(
            'no_previous_payment',
            ! $alreadyPaid,
            $alreadyPaid ? 'This leave has already been paid.' : 'No prior payment exists for this leave.',
        );

        // 12. no live duplicate case (excluding the one being (re)evaluated).
        // Scoped to the employee like check 11: otherwise the first settled
        // encashment of a leave type would block every other officer.
        $duplicate = LeavePaymentCase::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->when($request, fn ($q) => $q->where('leave_request_id', $request->id))
            ->when(! $request, fn ($q) => $q->whereNull('leave_request_id'))
            ->where('payment_type', $paymentType)
            ->whereNotIn('status', [LeavePaymentCase::STATUS_CANCELLED, LeavePaymentCase::STATUS_HR_REJECTED, LeavePaymentCase::STATUS_PAYMENT_REJECTED])
            ->when($excludeCaseId, fn ($q) => $q->whereKeyNot($excludeCaseId))
            ->exists();
        $add(
            'no_duplicate_case',
            ! $duplicate,
            $duplicate ? 'A payment case already exists for this leave.' : 'No duplicate payment case exists.',
        );

        // 13. configured loan / payroll restriction
        $blockOnLoan = PolicyRules::enabled($rules, self::RULE_BLOCK_ON_LOAN, false);
        $loanBlocked = $blockOnLoan && $loanOutstanding;
        $add(
            'loan_restriction',
            ! $loanBlocked,
            $loanBlocked ? 'An outstanding staff loan blocks payment.' : 'No loan restriction blocks payment.',
        );

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'policy_id' => $resolved['policy_id'],
            'policy_version' => $resolved['policy_version'],
            'source' => $resolved['source'],
            'snapshot' => [
                'evaluated_at' => now()->toIso8601String(),
                'payment_type' => $paymentType,
                'leave_type_id' => $leaveType->id,
                'leave_type_code' => $leaveType->code,
                'leave_request_id' => $request?->id,
                'days' => $days,
                'salary_basis' => $salaryBasis,
                'salary_amount' => $salaryAmount,
                'loan_outstanding' => $loanOutstanding,
                'policy_id' => $resolved['policy_id'],
                'policy_version' => $resolved['policy_version'],
                'source' => $resolved['source'],
                'blockers' => $blockers,
                'checks' => $checks,
            ],
        ];
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
