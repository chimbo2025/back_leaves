<?php

namespace App\Services;

use App\Models\OfficialLeaveLetter;
use Illuminate\Support\Facades\Cache;

/**
 * Document verification service (Phase 6).
 *
 * Handles QR token generation, registration, and public verification.
 * The verification endpoint must NOT expose:
 * - medical details (leave type names, reasons)
 * - salary information
 * - uploaded documents
 * - internal comments
 * - private approval metadata
 *
 * Only public-safe data is returned: document reference, employee name,
 * leave dates, issuing authority, issue date, document version, and
 * verification status.
 */
class DocumentVerificationService
{
    public const CACHE_PREFIX = 'document_verification:';

    public const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        protected SecureDocumentService $secure,
    ) {}

    /**
     * Generate a cryptographically random QR token.
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Register a QR token for a letter.
     */
    public function register(string $token, int $letterId): void
    {
        Cache::put(self::CACHE_PREFIX.$token, $letterId, self::CACHE_TTL);
    }

    /**
     * Revoke a QR token (when letter is revoked).
     */
    public function revoke(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX.$token);
    }

    /**
     * Verify a document by QR token.
     *
     * Returns public-safe data only, or null if token is invalid/revoked.
     *
     * @return array{valid:bool,document?:array,error?:string}
     */
    public function verify(string $token): array
    {
        $letterId = Cache::get(self::CACHE_PREFIX.$token);

        if (! $letterId) {
            return ['valid' => false, 'error' => 'Invalid or expired verification token.'];
        }

        $letter = OfficialLeaveLetter::with(['leaveRequest.employee', 'leaveRequest.leaveType', 'generator'])
            ->find($letterId);

        if (! $letter) {
            return ['valid' => false, 'error' => 'Document not found.'];
        }

        if ($letter->isRevoked()) {
            return ['valid' => false, 'error' => 'This document has been revoked.'];
        }

        $request = $letter->leaveRequest;
        $employee = $request?->employee;
        $leaveType = $request?->leaveType;

        return [
            'valid' => true,
            'document' => [
                'document_reference' => $this->buildReference($letter),
                'document_type' => $letter->document_type,
                'document_version' => $letter->document_version,
                'template_version' => $letter->template_version,
                'employee_name' => $employee?->full_name,
                'employee_id' => $employee?->id,
                'leave_type' => $leaveType?->code, // Only code, not name (no medical detail)
                'start_date' => $request?->start_date?->toDateString(),
                'end_date' => $request?->end_date?->toDateString(),
                'days' => $request?->days,
                'issue_date' => $letter->generated_at?->toDateString(),
                'issuing_authority' => $letter->generator?->full_name,
                'status' => $letter->isRevoked() ? 'revoked' : 'valid',
                'content_hash' => $letter->content_hash,
                'verified_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Build the official document reference.
     *
     * Format: DPP-LET-{YEAR}-{REQUEST_REF}-{TYPE}-v{VERSION}
     */
    protected function buildReference(OfficialLeaveLetter $letter): string
    {
        $year = $letter->generated_at?->year ?? now()->year;
        $typeCode = match ($letter->document_type) {
            OfficialLeaveLetter::TYPE_APPROVAL => 'APR',
            OfficialLeaveLetter::TYPE_REJECTION => 'REJ',
            OfficialLeaveLetter::TYPE_CANCELLATION => 'CAN',
            default => 'DOC',
        };
        $requestRef = $letter->leaveRequest?->ref ?? 'UNKNOWN';

        return "DPP-LET-{$year}-{$requestRef}-{$typeCode}-v{$letter->document_version}";
    }

    /**
     * Get the letter by QR token (for internal use).
     */
    public function getLetterByToken(string $token): ?OfficialLeaveLetter
    {
        $letterId = Cache::get(self::CACHE_PREFIX.$token);
        if (! $letterId) {
            return null;
        }

        return OfficialLeaveLetter::find($letterId);
    }
}
