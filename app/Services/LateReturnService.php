<?php

namespace App\Services;

use App\Models\BalanceTransaction;
use App\Models\LeaveReturn;
use App\Models\User;
use App\Support\ClosureResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Late-return review and deduction (Phase 3).
 *
 * The reviewer locks the case, validates the excused days, derives the
 * unauthorised and deducted days server-side (client values are never trusted),
 * records the decision, and — only when a deduction is approved — posts one
 * idempotent `late_return_deduction` debit to the append-only ledger. The
 * dynamic leave_balances table is never mutated; approved leave usage already
 * reflected by PolicyService::balanceFor() is not double counted.
 */
class LateReturnService
{
    public function __construct(
        protected PermissionService $permissions,
        protected BalanceTransactionService $ledger,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{excused_days?:int, apply_deduction?:bool}  $data
     * @return array{return: LeaveReturn, transaction: BalanceTransaction|null}
     */
    public function review(User $actor, LeaveReturn $return, array $data, ?string $comment = null): array
    {
        $employee = $actor->employee;

        if (! $employee) {
            throw new AuthorizationException('A return case can only be reviewed by an employee.');
        }

        return DB::transaction(function () use ($actor, $employee, $return, $data, $comment) {
            $locked = LeaveReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->employee_id === $employee->id) {
                throw ValidationException::withMessages(['authorization' => 'You cannot review your own return.']);
            }

            if (! in_array($locked->status, LeaveReturn::REVIEWABLE, true)) {
                throw ValidationException::withMessages([
                    'status' => "This return case is not awaiting review (current: {$locked->status}).",
                ]);
            }

            $lateDays = (int) $locked->late_days;
            $excusedDays = (int) ($data['excused_days'] ?? 0);

            if ($excusedDays < 0 || $excusedDays > $lateDays) {
                throw ValidationException::withMessages([
                    'excused_days' => "Excused days must be between 0 and {$lateDays}.",
                ]);
            }

            $applyDeduction = (bool) ($data['apply_deduction'] ?? false);
            $unauthorisedDays = $lateDays - $excusedDays;

            if ($excusedDays > 0 && ! $this->permissions->can($actor, 'leave_returns', 'excuse')) {
                throw new AuthorizationException('Excusing late-return days requires leave_returns.excuse.');
            }

            if ($applyDeduction && ! $this->permissions->can($actor, 'leave_returns', 'apply_deduction')) {
                throw new AuthorizationException('Applying a deduction requires leave_returns.apply_deduction.');
            }

            $deductedDays = $applyDeduction ? $unauthorisedDays : 0;

            $status = match (true) {
                $unauthorisedDays === 0 => LeaveReturn::STATUS_EXCUSED,
                $excusedDays > 0 => LeaveReturn::STATUS_PARTIALLY_EXCUSED,
                default => LeaveReturn::STATUS_UNAUTHORISED,
            };

            $locked->update([
                'status' => $status,
                'excused_days' => $excusedDays,
                'unauthorised_days' => $unauthorisedDays,
                'deducted_days' => $deductedDays,
                'reviewed_by' => $employee->id,
                'reviewed_at' => now(),
                'decision_reason' => $comment,
            ]);

            $transaction = $deductedDays > 0
                ? $this->applyDeduction($locked, $actor, $comment)
                : null;

            $this->audit->append('leave_return', (string) $locked->id, 'reviewed', $actor->id, [
                'leave_request_id' => $locked->leave_request_id,
                'late_days' => $lateDays,
                'excused_days' => $excusedDays,
                'unauthorised_days' => $unauthorisedDays,
                'deducted_days' => $deductedDays,
                'status' => $status,
                'balance_transaction_id' => $transaction?->id,
            ], reason: $comment);

            return ['return' => $locked->fresh(), 'transaction' => $transaction];
        });
    }

    /**
     * Close a decided case once every downstream action it implies is in place.
     *
     * Closure is deliberately separate from the review decision: HR may need to
     * verify a deduction, payroll may need to process an effect, or a required
     * document may still be missing. The case is only sealed when canClose()
     * is satisfied, and closing records who closed it, when, and why.
     *
     * Idempotent: a second close returns the already-closed case without writing
     * a second audit event or changing any data. A closed case is not editable
     * through any normal endpoint (report/explanation/review all refuse it);
     * corrections require a controlled reopening or correction event.
     */
    public function close(User $actor, LeaveReturn $return, ?string $comment = null): LeaveReturn
    {
        return DB::transaction(function () use ($actor, $return, $comment) {
            $locked = LeaveReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LeaveReturn::STATUS_CLOSED) {
                return $locked->fresh();
            }

            $closure = $this->canClose($locked);

            if (! $closure->ok) {
                throw ValidationException::withMessages(['status' => $closure->reason]);
            }

            $employee = $actor->employee;
            $decidedStatus = $locked->status;

            $locked->update([
                'status' => LeaveReturn::STATUS_CLOSED,
                'closed_by' => $employee?->id,
                'closed_at' => now(),
                'closure_reason' => $comment,
            ]);

            $this->audit->append('leave_return', (string) $locked->id, 'closed', $actor->id, [
                'leave_request_id' => $locked->leave_request_id,
                'decided_status' => $decidedStatus,
                'deducted_days' => (int) $locked->deducted_days,
                'balance_transaction_id' => $this->balanceTransaction($locked)?->id,
            ], reason: $comment);

            return $locked->fresh();
        });
    }

    /**
     * Whether a case may be closed. A decided case whose decision called for a
     * deduction may not be closed until that deduction has actually been posted.
     */
    public function canClose(LeaveReturn $return): ClosureResult
    {
        if (! in_array($return->status, LeaveReturn::CLOSABLE, true)) {
            return ClosureResult::invalid("This return case has not been decided (current: {$return->status}).");
        }

        if ((int) $return->deducted_days > 0 && $this->balanceTransaction($return) === null) {
            return ClosureResult::invalid('The approved balance deduction has not been posted to the ledger.');
        }

        return ClosureResult::valid();
    }

    /**
     * The ledger row backing a decided deduction, found by the stable
     * source_type/source_id pair (source_id is the leave_return_id). The unique
     * idempotency key return:{id}:late-deduction guarantees at most one.
     */
    protected function balanceTransaction(LeaveReturn $return): ?BalanceTransaction
    {
        return BalanceTransaction::query()
            ->where('source_type', 'LeaveReturn')
            ->where('source_id', (string) $return->id)
            ->where('transaction_type', 'late_return_deduction')
            ->first();
    }

    /**
     * Post the single idempotent deduction debit. Idempotency key
     * return:{id}:late-deduction means a retried review cannot deduct twice.
     */
    protected function applyDeduction(LeaveReturn $return, User $actor, ?string $comment): BalanceTransaction
    {
        $request = $return->leaveRequest;

        return $this->ledger->record([
            'employee_id' => $return->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'transaction_type' => 'late_return_deduction',
            'amount' => (int) $return->deducted_days,
            'direction' => 'debit',
            'source_type' => 'LeaveReturn',
            'source_id' => (string) $return->id,
            'leave_request_id' => $return->leave_request_id,
            'policy_id' => $return->policy_snapshot['policy_id'] ?? $request->policy_id,
            'reason_code' => 'late_return',
            'reason' => $comment,
            'metadata_json' => [
                'late_days' => (int) $return->late_days,
                'excused_days' => (int) $return->excused_days,
                'unauthorised_days' => (int) $return->unauthorised_days,
            ],
            'idempotency_key' => "return:{$return->id}:late-deduction",
            'created_by' => $actor->id,
        ]);
    }
}
