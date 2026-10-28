<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Official leave letter (Phase 6).
 *
 * One row per generated official document. The PDF is stored in private
 * storage; this model holds the metadata, versioning, and content hash.
 *
 * A letter is generated only after the leave request reaches 'approved'
 * status (or 'rejected' for rejection letters). Regeneration increments
 * document_version and preserves the prior version.
 *
 * QR verification uses a random token; the verification endpoint exposes
 * only public-safe data (no medical details, salary, internal comments).
 */
class OfficialLeaveLetter extends Model
{
    public const TYPE_APPROVAL = 'approval_letter';

    public const TYPE_REJECTION = 'rejection_letter';

    public const TYPE_CANCELLATION = 'cancellation_letter';

    public const TYPES = [
        self::TYPE_APPROVAL,
        self::TYPE_REJECTION,
        self::TYPE_CANCELLATION,
    ];

    protected $fillable = [
        'leave_request_id',
        'document_type',
        'document_version',
        'template_version',
        'policy_snapshot',
        'approval_snapshot',
        'content_hash',
        'storage_path',
        'storage_disk',
        'generated_by',
        'generated_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'qr_token',
    ];

    protected function casts(): array
    {
        return [
            'policy_snapshot' => 'array',
            'approval_snapshot' => 'array',
            'generated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'generated_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'revoked_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function isApprovalLetter(): bool
    {
        return $this->document_type === self::TYPE_APPROVAL;
    }

    public function getContentHash(): string
    {
        return $this->content_hash;
    }
}
