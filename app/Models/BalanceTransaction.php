<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only balance-effect ledger row (Phase 2 balance decision).
 *
 * Posted rows are immutable: update and delete are refused at the model layer so
 * a correction must be a new reversal transaction that references the original.
 */
class BalanceTransaction extends Model
{
    public const TYPES = [
        'accrual',
        'usage',
        'carry_forward',
        'expiry',
        'waiver_deduction',
        'forfeiture',
        'restoration',
        'manual_adjustment',
        'late_return_deduction',
        'correction',
    ];

    public const DIRECTIONS = ['credit', 'debit'];

    /**
     * Transaction types already represented by dynamic balance calculation.
     * These must never be summed again on top of the computed entitlement/usage
     * or the effect would be double counted.
     */
    public const DYNAMICALLY_REPRESENTED_TYPES = ['accrual', 'usage'];

    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_cycle_start', 'leave_cycle_end',
        'transaction_type', 'amount', 'direction', 'source_type', 'source_id',
        'leave_request_id', 'leave_waiver_id', 'policy_id', 'reason_code', 'reason',
        'metadata_json', 'idempotency_key', 'reversed_transaction_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'leave_cycle_start' => 'date:Y-m-d',
            'leave_cycle_end' => 'date:Y-m-d',
            'amount' => 'float',
            'metadata_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('Posted balance transactions are immutable; create a reversal transaction instead.');
        });

        static::deleting(function (): void {
            throw new DomainException('Posted balance transactions cannot be deleted; create a reversal transaction instead.');
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function leaveWaiver(): BelongsTo
    {
        return $this->belongsTo(LeaveWaiver::class, 'leave_waiver_id');
    }

    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }
}
