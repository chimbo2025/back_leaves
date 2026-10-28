<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave payment case (Phase 5).
 *
 * The lifecycle of a single payment arising from leave — encashment of an
 * approved entitlement, or purchase of extra leave days. It is deliberately
 * separate from the leave request: approval of leave, payment eligibility,
 * purchase terms, the calculation and the Accounting settlement are five
 * distinct steps on the same case, and each writes its own snapshot.
 *
 * The row is append-only in spirit. Once a case is paid it is terminal: no
 * transition method touches a STATUS_PAID case, and every snapshot is frozen.
 */
class LeavePaymentCase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_HR_REVIEW = 'under_hr_review';

    public const STATUS_HR_APPROVED = 'hr_approved';

    public const STATUS_HR_REJECTED = 'hr_rejected';

    public const STATUS_SENT_TO_ACCOUNTING = 'sent_to_accounting';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_PAYMENT_REJECTED = 'payment_rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_HR_REVIEW,
        self::STATUS_HR_APPROVED,
        self::STATUS_HR_REJECTED,
        self::STATUS_SENT_TO_ACCOUNTING,
        self::STATUS_PROCESSING,
        self::STATUS_PAID,
        self::STATUS_PAYMENT_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that no transition may leave. */
    public const TERMINAL = [
        self::STATUS_PAID,
        self::STATUS_HR_REJECTED,
        self::STATUS_PAYMENT_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** The status a case may still be cancelled from. */
    public const CANCELLABLE = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_HR_REVIEW,
        self::STATUS_HR_APPROVED,
        self::STATUS_SENT_TO_ACCOUNTING,
        self::STATUS_PROCESSING,
    ];

    public const PAYMENT_TYPE_ENCASHMENT = 'leave_encashment';

    public const PAYMENT_TYPE_PURCHASE = 'leave_purchase';

    public const PAYMENT_TYPES = [
        self::PAYMENT_TYPE_ENCASHMENT,
        self::PAYMENT_TYPE_PURCHASE,
    ];

    protected $fillable = [
        'employee_id', 'leave_request_id', 'leave_type_id', 'policy_id',
        'payment_type', 'status',
        'salary_basis', 'salary_amount', 'allowances_included', 'allowances_excluded',
        'eligible_amount', 'calculated_amount', 'currency',
        'calculation_snapshot', 'eligibility_snapshot',
        'created_by', 'submitted_by', 'submitted_at',
        'hr_reviewed_by', 'hr_reviewed_at',
        'sent_to_accounting_by', 'sent_to_accounting_at', 'accounting_notified_at',
        'processing_at', 'processed_by', 'paid_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'salary_amount' => 'decimal:2',
            'eligible_amount' => 'decimal:2',
            'calculated_amount' => 'decimal:2',
            'allowances_included' => 'array',
            'allowances_excluded' => 'array',
            'calculation_snapshot' => 'array',
            'eligibility_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'hr_reviewed_at' => 'datetime',
            'sent_to_accounting_at' => 'datetime',
            'accounting_notified_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'policy_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
