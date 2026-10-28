<?php

namespace App\Services;

use App\Events\LeaveWaiverDecided;
use App\Models\BalanceTransaction;
use App\Models\Employee;
use App\Models\LeaveWaiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LeaveWaiverService (Phase 2 - waiver / deferral / rescheduling).
 *
 * Lifecycle: draft -> submitted -> under_review -> approved | rejected.
 * Additional transitions: cancelled (owner, pre-decision), expired (a decision
 * finds the governed cycle has passed).
 *
 * Decisions are direct (no WorkflowResolver): a waiver is a simple two-step
 * flow. The decision still runs inside one database transaction with the waiver
 * row locked, so two reviewers cannot decide the same waiver concurrently, and
 * the balance effect is an idempotent append-only ledger transaction. The
 * effect_json column stays the policy decision snapshot; balance_transactions is
 * the applied record. No automatic forfeit or deduction happens without an
 * active approved policy.
 */
class LeaveWaiverService
{
    public const OWNER_EDITABLE = ['draft'];

    public const SUBMITTABLE = ['draft'];

    public const REVIEWABLE = ['submitted', 'under_review'];

    public const DECIDABLE = ['submitted', 'under_review'];

    public const ACTIONS = ['employee_declined', 'employee_requested_deferral', 'employer_deferred', 'rescheduled'];

    /**
     * Actions whose approval posts a ledger transaction. `rescheduled` changes
     * no entitlement, so its approval posts no transaction (see spec: only
     * effects that change leave entitlement are recorded).
     */
    public const BALANCE_EFFECT_ACTIONS = ['employee_declined', 'employee_requested_deferral', 'employer_deferred'];

    public function __construct(
        protected PolicyService $policy,
        protected AuditService $audit,
        protected BalanceTransactionService $ledger,
    ) {}

    public function createDraft(string $employeeId, array $data): LeaveWaiver
    {
        if (! in_array($data['requested_action'], self::ACTIONS, true)) {
            throw ValidationException::withMessages(['requested_action' => 'Invalid requested_action. Allowed: '.implode(', ', self::ACTIONS).'.']);
        }

        $policy = $data['leave_policy_id'] ?? $this->policy->currentPolicy($data['leave_type_id']);

        return LeaveWaiver::create([
            'employee_id' => $employeeId,
            'leave_type_id' => $data['leave_type_id'],
            'leave_policy_id' => $policy?->id,
            'leave_cycle_start' => $data['leave_cycle_start'] ?? null,
            'leave_cycle_end' => $data['leave_cycle_end'] ?? null,
            'requested_action' => $data['requested_action'],
            'reason' => $data['reason'] ?? null,
            'policy_snapshot' => $policy?->toArray(),
            'status' => 'draft',
        ]);
    }

    public function submit(LeaveWaiver $waiver, Employee $actor, ?string $actorUserId = null): LeaveWaiver
    {
        if ($actor->id !== $waiver->employee_id) {
            throw ValidationException::withMessages(['authorization' => 'Only the waiver owner can submit it.']);
        }

        if (! in_array($waiver->status, self::SUBMITTABLE, true)) {
            throw ValidationException::withMessages(['status' => "Only draft waivers can be submitted (current: {$waiver->status})."]);
        }

        if ($this->cycleHasPassed($waiver)) {
            $waiver->update(['status' => 'expired', 'requested_by' => $actor->id]);
            $this->audit->append('leave_waiver', (string) $waiver->id, 'expired_on_submit', $actorUserId, ['cycle_end' => $waiver->leave_cycle_end?->toDateString()]);

            return $waiver->fresh();
        }

        DB::transaction(function () use ($waiver, $actor, $actorUserId) {
            $waiver->update(['status' => 'submitted', 'requested_by' => $actor->id]);
            $this->audit->append('leave_waiver', (string) $waiver->id, 'submit', $actorUserId, [
                'leave_type_id' => $waiver->leave_type_id,
                'requested_action' => $waiver->requested_action,
            ]);
        });

        return $waiver->fresh();
    }

    /** Move a submitted waiver under review before a decision is recorded. */
    public function review(LeaveWaiver $waiver, Employee $actor, ?string $comment = null, ?string $actorUserId = null): LeaveWaiver
    {
        if (! in_array($waiver->status, self::REVIEWABLE, true)) {
            throw ValidationException::withMessages(['status' => "Only submitted waivers can be reviewed (current: {$waiver->status})."]);
        }

        if ($this->cycleHasPassed($waiver)) {
            DB::transaction(function () use ($waiver, $actor, $actorUserId) {
                $waiver->update(['status' => 'expired', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                $this->audit->append('leave_waiver', (string) $waiver->id, 'expired', $actorUserId, ['cycle_end' => $waiver->leave_cycle_end?->toDateString()]);
            });

            return $waiver->fresh();
        }

        DB::transaction(function () use ($waiver, $actor, $comment, $actorUserId) {
            $waiver->update([
                'status' => 'under_review',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'decision_comment' => $comment ?: $waiver->decision_comment,
            ]);
            $this->audit->append('leave_waiver', (string) $waiver->id, 'review', $actorUserId, ['reviewed_by' => $actor->id]);
        });

        return $waiver->fresh();
    }

    /**
     * Approve a waiver. Locks the row, records the decision snapshot, posts one
     * idempotent balance transaction when the action changes entitlement, and
     * writes an audit event — all inside a single transaction.
     *
     * @return array{waiver: LeaveWaiver, effect: array<string,mixed>|null, transaction: BalanceTransaction|null}
     */
    public function approve(LeaveWaiver $waiver, Employee $actor, ?string $comment = null, ?string $actorUserId = null): array
    {
        $result = DB::transaction(function () use ($waiver, $actor, $comment, $actorUserId) {
            $locked = $this->lockForDecision($waiver, $actor);

            if ($this->cycleHasPassed($locked)) {
                $this->markExpired($locked, $actor, $comment, $actorUserId, 'approved');

                return ['waiver' => $locked->fresh(), 'effect' => null, 'transaction' => null];
            }

            $effect = $this->effectForApproval($locked);

            $locked->update([
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'decision_comment' => $comment,
                'effect_json' => $effect,
            ]);

            $transaction = $this->applyBalanceEffect($locked, $actorUserId);

            $this->audit->append('leave_waiver', (string) $locked->id, 'approved', $actorUserId, [
                'leave_type_id' => $locked->leave_type_id,
                'requested_action' => $locked->requested_action,
                'effect' => $effect,
                'balance_transaction_id' => $transaction?->id,
            ], reason: $comment);

            return [
                'waiver' => $locked->fresh(),
                'effect' => $effect,
                'transaction' => $transaction,
            ];
        });

        // Phase 4: notify the employee once the decision is recorded. Only an
        // actual approval notifies — an expired attempt is not an approval.
        if ($result['waiver']->status === 'approved') {
            event(new LeaveWaiverDecided($result['waiver'], true));
        }

        return $result;
    }

    /**
     * Reject a waiver. No balance effect is ever posted for a rejection.
     */
    public function reject(LeaveWaiver $waiver, Employee $actor, ?string $comment = null, ?string $actorUserId = null): LeaveWaiver
    {
        $decided = DB::transaction(function () use ($waiver, $actor, $comment, $actorUserId) {
            $locked = $this->lockForDecision($waiver, $actor);

            if ($this->cycleHasPassed($locked)) {
                $this->markExpired($locked, $actor, $comment, $actorUserId, 'rejected');

                return $locked->fresh();
            }

            $locked->update([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'decision_comment' => $comment,
                'effect_json' => null,
            ]);

            $this->audit->append('leave_waiver', (string) $locked->id, 'rejected', $actorUserId, [
                'leave_type_id' => $locked->leave_type_id,
                'requested_action' => $locked->requested_action,
            ], reason: $comment);

            return $locked->fresh();
        });

        if ($decided->status === 'rejected') {
            event(new LeaveWaiverDecided($decided, false));
        }

        return $decided;
    }

    public function cancel(LeaveWaiver $waiver, Employee $actor, ?string $actorUserId = null): LeaveWaiver
    {
        if ($actor->id !== $waiver->employee_id) {
            throw ValidationException::withMessages(['authorization' => 'Only the waiver owner can cancel it.']);
        }

        if (in_array($waiver->status, ['approved', 'rejected', 'cancelled', 'expired'], true)) {
            throw ValidationException::withMessages(['status' => "A {$waiver->status} waiver can no longer be cancelled."]);
        }

        DB::transaction(function () use ($waiver, $actorUserId) {
            $waiver->update(['status' => 'cancelled']);
            $this->audit->append('leave_waiver', (string) $waiver->id, 'cancelled', $actorUserId, ['employee_id' => $waiver->employee_id]);
        });

        return $waiver->fresh();
    }

    /**
     * Post the idempotent ledger transaction for an approved waiver, if the
     * action changes entitlement and an active approved policy applies.
     *
     * Idempotency key: waiver:{id}:effect:{action} — a retry returns the row
     * already posted instead of minting a duplicate effect.
     */
    public function applyBalanceEffect(LeaveWaiver $waiver, ?string $actorUserId = null): ?BalanceTransaction
    {
        if (! $waiver->leave_policy_id) {
            return null;
        }

        $spec = $this->ledgerSpec($waiver);
        if ($spec === null) {
            return null;
        }

        $days = $this->cycleDays($waiver);
        if ($days <= 0) {
            return null;
        }

        return $this->ledger->record([
            'employee_id' => $waiver->employee_id,
            'leave_type_id' => $waiver->leave_type_id,
            'leave_cycle_start' => $waiver->leave_cycle_start?->toDateString(),
            'leave_cycle_end' => $waiver->leave_cycle_end?->toDateString(),
            'transaction_type' => $spec['transaction_type'],
            'amount' => $days,
            'direction' => $spec['direction'],
            'source_type' => 'leave_waiver',
            'source_id' => (string) $waiver->id,
            'leave_waiver_id' => $waiver->id,
            'policy_id' => $waiver->leave_policy_id,
            'reason_code' => $spec['reason_code'],
            'reason' => $waiver->reason,
            'metadata_json' => [
                'requested_action' => $waiver->requested_action,
                'effect_snapshot' => $waiver->effect_json,
            ],
            'idempotency_key' => "waiver:{$waiver->id}:effect:{$waiver->requested_action}",
            'created_by' => $actorUserId,
        ]);
    }

    /**
     * Compute the intended balance effect snapshot (kept on effect_json). No
     * balance row is mutated: the applied record lives in balance_transactions.
     */
    protected function effectForApproval(LeaveWaiver $waiver): array
    {
        $balance = $this->policy->balanceFor($waiver->employee_id, $waiver->leave_type_id);
        $cycleDays = $this->cycleDays($waiver);

        return match ($waiver->requested_action) {
            'employee_declined' => [
                'type' => 'balance_reduction_from_cycle',
                'days_referenced' => $cycleDays,
                'balance_before' => $balance['remaining'],
                'balance_after' => max(0, $balance['remaining'] - $cycleDays),
                'ledger' => 'balance_transactions',
                'note' => 'Recorded as an append-only ledger debit; the dynamic entitlement is never mutated.',
            ],
            'employee_requested_deferral', 'employer_deferred' => [
                'type' => 'deferral_credit',
                'days_carried' => $cycleDays,
                'applied_to' => 'next_cycle',
                'ledger' => 'balance_transactions',
                'note' => 'Recorded as an append-only ledger credit; see policy carry-over rules.',
            ],
            'rescheduled' => [
                'type' => 'rescheduled',
                'from_cycle' => [$waiver->leave_cycle_start?->toDateString(), $waiver->leave_cycle_end?->toDateString()],
                'ledger' => null,
                'note' => 'Rescheduling changes no entitlement and posts no balance transaction; a new leave request is required for the new window.',
            ],
        };
    }

    /**
     * @return array{transaction_type: string, direction: string, reason_code: string}|null
     */
    protected function ledgerSpec(LeaveWaiver $waiver): ?array
    {
        return match ($waiver->requested_action) {
            'employee_declined' => ['transaction_type' => 'forfeiture', 'direction' => 'debit', 'reason_code' => 'employee_declined'],
            'employee_requested_deferral', 'employer_deferred' => ['transaction_type' => 'carry_forward', 'direction' => 'credit', 'reason_code' => 'deferral'],
            default => null,
        };
    }

    protected function cycleDays(LeaveWaiver $waiver): int
    {
        if (! $waiver->leave_cycle_start || ! $waiver->leave_cycle_end) {
            return 0;
        }

        return (int) $this->policy->dayCount(
            $waiver->leave_cycle_start->toDateString(),
            $waiver->leave_cycle_end->toDateString(),
        );
    }

    /**
     * Lock the waiver row and enforce the decision guards shared by approve and
     * reject: ownership, decision rights, and decidable status.
     */
    protected function lockForDecision(LeaveWaiver $waiver, Employee $actor): LeaveWaiver
    {
        $locked = LeaveWaiver::query()->whereKey($waiver->id)->lockForUpdate()->firstOrFail();

        if ($actor->id === $locked->employee_id) {
            throw ValidationException::withMessages(['authorization' => 'An employee cannot decide their own waiver.']);
        }

        if (! in_array($locked->status, self::DECIDABLE, true)) {
            throw ValidationException::withMessages(['status' => "This waiver has already been decided (current: {$locked->status})."]);
        }

        return $locked;
    }

    protected function markExpired(LeaveWaiver $waiver, Employee $actor, ?string $comment, ?string $actorUserId, string $attempted): void
    {
        $waiver->update([
            'status' => 'expired',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'decision_comment' => $comment,
        ]);

        $this->audit->append('leave_waiver', (string) $waiver->id, 'expired', $actorUserId, [
            'attempted_decision' => $attempted,
        ], reason: $comment);
    }

    protected function cycleHasPassed(LeaveWaiver $waiver): bool
    {
        return $waiver->leave_cycle_end !== null
            && $waiver->leave_cycle_end->lt(now()->toDateString());
    }
}
