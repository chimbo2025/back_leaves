<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OfficialLeaveLetter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Official leave letter service (Phase 6).
 *
 * Generates an official PDF letter after a leave request is approved.
 * The letter is stored in private storage; metadata and content hash are
 * persisted in the database. Generation is idempotent: repeated calls
 * return the existing letter unless content has changed, in which case a
 * new version is created.
 *
 * The QR verification token is a random string; the public verification
 * endpoint uses it to look up the letter and return public-safe data only.
 */
class OfficialLeaveLetterService
{
    public function __construct(
        protected LeaveDocumentRenderer $renderer,
        protected AuditService $audit,
        protected DocumentVerificationService $verification,
    ) {}

    /**
     * Generate the official approval letter for an approved leave request.
     *
     * @param  array{template_version?:?int, force_regenerate?:bool}  $options
     */
    public function generateApprovalLetter(
        LeaveRequest $request,
        Employee $generator,
        array $options = []
    ): OfficialLeaveLetter {
        if ($request->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => "Official letter can only be generated for approved requests (current: {$request->status}).",
            ]);
        }

        // Check for existing active letter.
        $existing = OfficialLeaveLetter::where('leave_request_id', $request->id)
            ->where('document_type', OfficialLeaveLetter::TYPE_APPROVAL)
            ->whereNull('revoked_at')
            ->orderByDesc('document_version')
            ->first();

        if ($existing && ! ($options['force_regenerate'] ?? false)) {
            // Content hasn't changed; return existing.
            if (! $this->contentChanged($request, $existing)) {
                return $existing;
            }
        }

        return $this->generateLetter($request, $generator, OfficialLeaveLetter::TYPE_APPROVAL, $options);
    }

    /**
     * Generate a rejection letter.
     */
    public function generateRejectionLetter(
        LeaveRequest $request,
        Employee $generator,
        array $options = []
    ): OfficialLeaveLetter {
        if (! in_array($request->status, ['rejected', 'returned_for_correction'], true)) {
            throw ValidationException::withMessages([
                'status' => "Rejection letter requires a rejected or returned request (current: {$request->status}).",
            ]);
        }

        $existing = OfficialLeaveLetter::where('leave_request_id', $request->id)
            ->where('document_type', OfficialLeaveLetter::TYPE_REJECTION)
            ->whereNull('revoked_at')
            ->orderByDesc('document_version')
            ->first();

        if ($existing && ! ($options['force_regenerate'] ?? false)) {
            if (! $this->contentChanged($request, $existing)) {
                return $existing;
            }
        }

        return $this->generateLetter($request, $generator, OfficialLeaveLetter::TYPE_REJECTION, $options);
    }

    /**
     * Generate a cancellation letter.
     */
    public function generateCancellationLetter(
        LeaveRequest $request,
        Employee $generator,
        array $options = []
    ): OfficialLeaveLetter {
        $existing = OfficialLeaveLetter::where('leave_request_id', $request->id)
            ->where('document_type', OfficialLeaveLetter::TYPE_CANCELLATION)
            ->whereNull('revoked_at')
            ->orderByDesc('document_version')
            ->first();

        if ($existing && ! ($options['force_regenerate'] ?? false)) {
            if (! $this->contentChanged($request, $existing)) {
                return $existing;
            }
        }

        return $this->generateLetter($request, $generator, OfficialLeaveLetter::TYPE_CANCELLATION, $options);
    }

    /**
     * Core generation logic.
     */
    protected function generateLetter(
        LeaveRequest $request,
        Employee $generator,
        string $documentType,
        array $options
    ): OfficialLeaveLetter {
        $templateVersion = $options['template_version'] ?? config('leave.documents.template_version', 1);
        $nextVersion = $this->nextVersion($request, $documentType);

        return DB::transaction(function () use ($request, $generator, $documentType, $templateVersion, $nextVersion) {
            // Build the policy and approval snapshots.
            $policySnapshot = $request->policy_snapshot ?? $request->policy?->toArray() ?? [];
            $approvalSnapshot = $this->buildApprovalSnapshot($request);

            // Render the PDF.
            $pdfBytes = $this->renderer->render($documentType, [
                'request' => $request->loadMissing(['employee', 'leaveType', 'policy']),
                'policy_snapshot' => $policySnapshot,
                'approval_snapshot' => $approvalSnapshot,
                'generator' => $generator,
                'template_version' => $templateVersion,
                'document_version' => $nextVersion,
                'qr_token' => $this->verification->generateToken(),
            ]);

            $contentHash = hash('sha256', $pdfBytes);
            $qrToken = $this->verification->generateToken();

            // Store in private storage.
            $storagePath = "official-letters/{$request->id}/{$documentType}_v{$nextVersion}_{$contentHash}.pdf";
            Storage::disk('local')->put($storagePath, $pdfBytes);

            // Create the record.
            $letter = OfficialLeaveLetter::create([
                'leave_request_id' => $request->id,
                'document_type' => $documentType,
                'document_version' => $nextVersion,
                'template_version' => $templateVersion,
                'policy_snapshot' => $policySnapshot,
                'approval_snapshot' => $approvalSnapshot,
                'content_hash' => $contentHash,
                'storage_path' => $storagePath,
                'storage_disk' => 'local',
                'generated_by' => $generator->id,
                'generated_at' => now(),
                'qr_token' => $qrToken,
            ]);

            // Register QR token for verification.
            $this->verification->register($qrToken, $letter->id);

            $this->audit->append('leave_request', (string) $request->id, 'official_letter.generated', $generator->user_id, [
                'letter_id' => (string) $letter->id,
                'document_type' => $documentType,
                'document_version' => $nextVersion,
                'content_hash' => $contentHash,
                'qr_token' => $qrToken,
            ]);

            return $letter;
        });
    }

    /**
     * Check if the request content has changed since the letter was generated.
     */
    protected function contentChanged(LeaveRequest $request, OfficialLeaveLetter $existing): bool
    {
        $currentPolicy = $request->policy_snapshot ?? $request->policy?->toArray() ?? [];
        $currentApproval = $this->buildApprovalSnapshot($request);

        return $currentPolicy !== ($existing->policy_snapshot ?? [])
            || $currentApproval !== ($existing->approval_snapshot ?? []);
    }

    /**
     * Build the approval chain snapshot.
     */
    protected function buildApprovalSnapshot(LeaveRequest $request): array
    {
        return $request->assignments()
            ->with(['assignee', 'approvalActions'])
            ->orderBy('id')
            ->get()
            ->map(function ($assignment) {
                $actions = $assignment->approvalActions->map(function ($action) {
                    return [
                        'actor' => $action->actor?->full_name,
                        'actor_id' => $action->actor_employee_id,
                        'decision' => $action->decision,
                        'comments' => $action->comments,
                        'acted_at' => $action->acted_at?->toIso8601String(),
                        'workflow_rule' => $action->workflow_rule_used,
                    ];
                })->values()->all();

                return [
                    'role' => $assignment->assigned_role_id,
                    'assignee' => $assignment->assignee?->full_name,
                    'assignee_id' => $assignment->assigned_employee_id,
                    'status' => $assignment->status,
                    'via' => $assignment->approvalActions->first()?->decision ?? 'pending',
                    'actions' => $actions,
                ];
            })->values()->all();
    }

    /**
     * Get the next document version number.
     */
    protected function nextVersion(LeaveRequest $request, string $documentType): int
    {
        return (int) OfficialLeaveLetter::where('leave_request_id', $request->id)
            ->where('document_type', $documentType)
            ->max('document_version') + 1;
    }

    /**
     * Revoke a letter.
     */
    public function revoke(OfficialLeaveLetter $letter, Employee $revoker, string $reason): OfficialLeaveLetter
    {
        if ($letter->isRevoked()) {
            return $letter->fresh();
        }

        $letter->update([
            'revoked_at' => now(),
            'revoked_by' => $revoker->id,
            'revocation_reason' => $reason,
        ]);

        // Invalidate QR token.
        $this->verification->revoke($letter->qr_token);

        $this->audit->append('leave_request', (string) $letter->leave_request_id, 'official_letter.revoked', $revoker->user_id, [
            'letter_id' => (string) $letter->id,
            'document_type' => $letter->document_type,
            'document_version' => $letter->document_version,
            'reason' => $reason,
        ]);

        return $letter->fresh();
    }

    /**
     * Get the latest active letter for a request.
     */
    public function getLatest(LeaveRequest $request, string $documentType = OfficialLeaveLetter::TYPE_APPROVAL): ?OfficialLeaveLetter
    {
        return OfficialLeaveLetter::where('leave_request_id', $request->id)
            ->where('document_type', $documentType)
            ->whereNull('revoked_at')
            ->orderByDesc('document_version')
            ->first();
    }

    /**
     * Get all versions for a request.
     */
    public function getAllVersions(LeaveRequest $request, string $documentType): Collection
    {
        return OfficialLeaveLetter::where('leave_request_id', $request->id)
            ->where('document_type', $documentType)
            ->orderBy('document_version')
            ->get();
    }
}
