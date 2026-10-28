<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Return-to-work case (Phase 3).
 *
 * Opened when a leave request is finally approved; the employee reports their
 * actual return, and a late return is reviewed for excusal or a balance
 * deduction. The expected return date and its snapshot are frozen at open time.
 */
class LeaveReturn extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REPORTED_ON_TIME = 'reported_on_time';

    public const STATUS_LATE_PENDING_REVIEW = 'late_pending_review';

    public const STATUS_EXCUSED = 'excused';

    public const STATUS_PARTIALLY_EXCUSED = 'partially_excused';

    public const STATUS_UNAUTHORISED = 'unauthorised';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REPORTED_ON_TIME,
        self::STATUS_LATE_PENDING_REVIEW,
        self::STATUS_EXCUSED,
        self::STATUS_PARTIALLY_EXCUSED,
        self::STATUS_UNAUTHORISED,
        self::STATUS_CLOSED,
    ];

    /** Statuses a reviewer may decide. */
    public const REVIEWABLE = [self::STATUS_LATE_PENDING_REVIEW];

    /** Decided statuses that may be closed. */
    public const CLOSABLE = [
        self::STATUS_REPORTED_ON_TIME,
        self::STATUS_EXCUSED,
        self::STATUS_PARTIALLY_EXCUSED,
        self::STATUS_UNAUTHORISED,
    ];

    protected $fillable = [
        'leave_request_id', 'employee_id', 'expected_return_date', 'actual_return_date',
        'status', 'reported_by', 'reported_at', 'reviewed_by', 'reviewed_at', 'decision_reason',
        'explanation', 'explanation_status', 'supporting_document_id',
        'late_days', 'excused_days', 'unauthorised_days', 'deducted_days', 'policy_snapshot',
        'closed_by', 'closed_at', 'closure_reason',
    ];

    protected function casts(): array
    {
        return [
            'expected_return_date' => 'date:Y-m-d',
            'actual_return_date' => 'date:Y-m-d',
            'reported_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'late_days' => 'integer',
            'excused_days' => 'integer',
            'unauthorised_days' => 'integer',
            'deducted_days' => 'integer',
            'policy_snapshot' => 'array',
        ];
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'closed_by');
    }

    public function supportingDocument(): BelongsTo
    {
        return $this->belongsTo(LeaveDocument::class, 'supporting_document_id');
    }
}
