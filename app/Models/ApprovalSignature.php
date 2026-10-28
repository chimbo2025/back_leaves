<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Digital approval signature (Phase 6).
 *
 * One row per approval action, capturing the actor's signature at the moment
 * of decision. The signature_value is an opaque reference (e.g., HSM token ID),
 * never the raw private key. The signature_hash is a SHA-256 of the canonical
 * payload for integrity verification.
 */
class ApprovalSignature extends Model
{
    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_RETURNED = 'returned';

    public const ACTIONS = [
        self::ACTION_APPROVED,
        self::ACTION_REJECTED,
        self::ACTION_RETURNED,
    ];

    public const TYPE_DIGITAL = 'digital';

    public const TYPE_WET_INK = 'wet_ink_reference';

    public const TYPES = [
        self::TYPE_DIGITAL,
        self::TYPE_WET_INK,
    ];

    protected $fillable = [
        'leave_request_id',
        'approval_action_id',
        'actor_id',
        'approval_action',
        'signature_type',
        'signature_value',
        'signature_hash',
        'signed_at',
        'ip_address',
        'user_agent',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function approvalAction(): BelongsTo
    {
        return $this->belongsTo(ApprovalAction::class, 'approval_action_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id');
    }

    public function isValidHash(string $canonicalPayload): bool
    {
        return hash_equals($this->signature_hash, hash('sha256', $canonicalPayload));
    }
}
