<?php

namespace App\Services;

use App\Models\BalanceTransaction;
use Illuminate\Validation\ValidationException;

/**
 * Append-only balance ledger (Phase 2 balance decision).
 *
 * Records only balance effects that are not already represented by the dynamic
 * entitlement/usage calculation. Writes are idempotent on `idempotency_key`, so
 * a retried approval cannot post the same effect twice. Corrections are made by
 * posting a reversal transaction; posted rows are never edited or deleted.
 */
class BalanceTransactionService
{
    /**
     * Post an idempotent ledger transaction.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): BalanceTransaction
    {
        $type = $attributes['transaction_type'] ?? null;
        $direction = $attributes['direction'] ?? null;
        $amount = (float) ($attributes['amount'] ?? 0);
        $key = $attributes['idempotency_key'] ?? null;

        if (! in_array($type, BalanceTransaction::TYPES, true)) {
            throw ValidationException::withMessages(['transaction_type' => "Unknown transaction_type '{$type}'."]);
        }

        if (! in_array($direction, BalanceTransaction::DIRECTIONS, true)) {
            throw ValidationException::withMessages(['direction' => "Unknown direction '{$direction}'."]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Balance transaction amounts must be positive; use direction to express the sign.']);
        }

        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'A balance transaction must carry an idempotency_key.']);
        }

        return BalanceTransaction::firstOrCreate(
            ['idempotency_key' => $key],
            $attributes,
        );
    }

    /**
     * Reverse a posted transaction by posting the opposite row. Corrections are
     * never edits; they reference the original through reversed_transaction_id.
     */
    public function reverse(BalanceTransaction $original, string $reason, ?string $actorId = null): BalanceTransaction
    {
        return $this->record([
            'employee_id' => $original->employee_id,
            'leave_type_id' => $original->leave_type_id,
            'leave_cycle_start' => $original->leave_cycle_start?->toDateString(),
            'leave_cycle_end' => $original->leave_cycle_end?->toDateString(),
            'transaction_type' => 'correction',
            'amount' => (float) $original->amount,
            'direction' => $original->direction === 'debit' ? 'credit' : 'debit',
            'source_type' => $original->source_type,
            'source_id' => $original->source_id,
            'leave_request_id' => $original->leave_request_id,
            'leave_waiver_id' => $original->leave_waiver_id,
            'policy_id' => $original->policy_id,
            'reason_code' => 'reversal',
            'reason' => $reason,
            'metadata_json' => ['reverses_idempotency_key' => $original->idempotency_key],
            'idempotency_key' => "reversal:{$original->id}",
            'reversed_transaction_id' => $original->id,
            'created_by' => $actorId,
        ]);
    }

    /**
     * Net ledger contribution for an employee + leave type, excluding effects
     * already represented by the dynamic calculation (accrual, usage).
     *
     * @return array{credits: float, debits: float, net: float}
     */
    public function ledgerDelta(string $employeeId, string $leaveTypeId): array
    {
        $rows = BalanceTransaction::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereNotIn('transaction_type', BalanceTransaction::DYNAMICALLY_REPRESENTED_TYPES)
            ->get(['direction', 'amount']);

        $credits = 0.0;
        $debits = 0.0;

        foreach ($rows as $row) {
            if ($row->direction === 'credit') {
                $credits += (float) $row->amount;
            } else {
                $debits += (float) $row->amount;
            }
        }

        return ['credits' => $credits, 'debits' => $debits, 'net' => $credits - $debits];
    }
}
