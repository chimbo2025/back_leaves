<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report export record (Phase 7).
 *
 * Tracks an asynchronous report export from request through completion,
 * expiry, or cancellation. The file lives on the private 'local' disk;
 * downloads use temporary signed URLs.
 */
class ReportExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    public const FORMAT_CSV = 'csv';

    public const FORMAT_PDF = 'pdf';

    public const FORMATS = [
        self::FORMAT_CSV,
        self::FORMAT_PDF,
    ];

    /** Report types supported. */
    public const TYPE_LEAVE_REQUESTS = 'leave_requests';

    public const TYPE_EMPLOYEE_HISTORY = 'employee_history';

    public const TYPE_LEAVE_PLANS = 'leave_plans';

    public const TYPE_WAIVERS = 'waivers';

    public const TYPE_RETURNS = 'returns';

    public const TYPE_BALANCE = 'balance';

    public const TYPE_PAYMENTS = 'payments';

    public const TYPE_POLICIES = 'policies';

    public const TYPE_APPROVAL_PERFORMANCE = 'approval_performance';

    public const TYPE_DOCUMENTS = 'documents';

    public const TYPE_AUDIT = 'audit';

    public const TYPES = [
        self::TYPE_LEAVE_REQUESTS,
        self::TYPE_EMPLOYEE_HISTORY,
        self::TYPE_LEAVE_PLANS,
        self::TYPE_WAIVERS,
        self::TYPE_RETURNS,
        self::TYPE_BALANCE,
        self::TYPE_PAYMENTS,
        self::TYPE_POLICIES,
        self::TYPE_APPROVAL_PERFORMANCE,
        self::TYPE_DOCUMENTS,
        self::TYPE_AUDIT,
    ];

    protected $fillable = [
        'report_type',
        'requested_by',
        'filters_json',
        'scope_snapshot_json',
        'format',
        'status',
        'file_path',
        'file_disk',
        'file_hash',
        'row_count',
        'column_count',
        'generated_at',
        'expires_at',
        'cancelled_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'filters_json' => 'array',
            'scope_snapshot_json' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->file_path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
