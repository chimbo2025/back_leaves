<?php

namespace App\Services;

use App\Models\OfficialLeaveLetter;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Secure document service (Phase 6).
 *
 * Handles secure access to generated documents:
 * - Private storage enforcement (never public disk)
 * - Temporary signed URLs for downloads
 * - Download audit events
 * - Revoked document rejection
 * - Scope enforcement (department/zone)
 */
class SecureDocumentService
{
    public const DOWNLOAD_TTL = 300; // 5 minutes for signed URLs

    public function __construct(
        protected AuditService $audit,
        protected AccessScopeService $accessScope,
    ) {}

    /**
     * Create a temporary download response for an official letter.
     *
     * Enforces:
     * - Letter exists and is not revoked
     * - Caller has permission to download
     * - Caller has scope over the employee
     * - Private storage disk
     * - Audit event recorded
     */
    public function download(OfficialLeaveLetter $letter, User $user, ?string $ip = null): Response
    {
        $this->authorizeDownload($user, $letter);

        if ($letter->isRevoked()) {
            throw ValidationException::withMessages(['document' => 'This document has been revoked and cannot be downloaded.']);
        }

        if (! $letter->storage_path || ! Storage::disk($letter->storage_disk)->exists($letter->storage_path)) {
            throw ValidationException::withMessages(['document' => 'Document file is missing from storage.']);
        }

        $this->audit->append('leave_request', (string) $letter->leave_request_id, 'official_letter.download', $user->id, [
            'letter_id' => (string) $letter->id,
            'document_type' => $letter->document_type,
            'document_version' => $letter->document_version,
            'content_hash' => $letter->content_hash,
            'ip' => $ip ?? request()->ip(),
        ], at: now());

        return Storage::disk($letter->storage_disk)->download(
            $letter->storage_path,
            $this->buildFilename($letter)
        );
    }

    /**
     * Generate a temporary signed URL for the document.
     *
     * Useful for frontend direct download without proxying through the API.
     */
    public function temporaryUrl(OfficialLeaveLetter $letter, User $user): string
    {
        $this->authorizeDownload($user, $letter);

        if ($letter->isRevoked()) {
            throw ValidationException::withMessages(['document' => 'This document has been revoked.']);
        }

        return Storage::disk($letter->storage_disk)->temporaryUrl(
            $letter->storage_path,
            now()->addSeconds(self::DOWNLOAD_TTL)
        );
    }

    /**
     * Get the raw file content for internal processing (e.g., email attachment).
     */
    public function getContent(OfficialLeaveLetter $letter): string
    {
        if ($letter->isRevoked()) {
            throw ValidationException::withMessages(['document' => 'This document has been revoked.']);
        }

        if (! $letter->storage_path || ! Storage::disk($letter->storage_disk)->exists($letter->storage_path)) {
            throw ValidationException::withMessages(['document' => 'Document file is missing from storage.']);
        }

        return Storage::disk($letter->storage_disk)->get($letter->storage_path);
    }

    /**
     * Verify the content hash matches the stored file.
     */
    public function verifyIntegrity(OfficialLeaveLetter $letter): bool
    {
        if (! $letter->storage_path || ! Storage::disk($letter->storage_disk)->exists($letter->storage_path)) {
            return false;
        }

        $content = Storage::disk($letter->storage_disk)->get($letter->storage_path);
        $actualHash = hash('sha256', $content);

        return hash_equals($letter->content_hash, $actualHash);
    }

    /**
     * Authorize the user to download the letter.
     *
     * Rules:
     * - HR with leave_documents.download_hr AND scope over the employee
     * - Employee themselves (leave_documents.download_own)
     * - FINAL_AUTHORITY/BREAK_GLASS with scope
     */
    protected function authorizeDownload(User $user, OfficialLeaveLetter $letter): void
    {
        $employee = $user->employee;

        if (! $employee) {
            throw ValidationException::withMessages(['authorization' => 'Only employees can download official letters.']);
        }

        $perm = app(PermissionService::class);

        // Subject of the letter: can always download their own.
        if ($employee->id === $letter->leaveRequest?->employee_id) {
            if (! $perm->can($user, 'leave_documents', 'download_own')) {
                throw ValidationException::withMessages(['authorization' => 'You do not have permission to download your own leave letters.']);
            }

            return;
        }

        // HR / authority: need download_hr + scope.
        if (! $perm->can($user, 'leave_documents', 'download_hr')) {
            throw ValidationException::withMessages(['authorization' => 'You do not have permission to download leave letters for other employees.']);
        }

        // Check scope.
        $scope = $this->accessScope->forUser($user, 'leave_documents');
        if ($scope->canViewAllZones) {
            return; // cross-zone access
        }

        $targetEmployee = $letter->leaveRequest?->employee;
        if (! $targetEmployee) {
            throw ValidationException::withMessages(['authorization' => 'Cannot determine scope for this document.']);
        }

        if (! $scope->hasZoneScope()) {
            throw ValidationException::withMessages(['authorization' => 'You do not have scope over this employee.']);
        }

        if (! in_array($targetEmployee->zone_id, $scope->allowedZoneIds, true)
            && ! in_array($targetEmployee->department_id, $scope->allowedDepartmentIds, true)) {
            throw ValidationException::withMessages(['authorization' => 'This employee is outside your authorised zone/department.']);
        }
    }

    /**
     * Build a safe filename for download.
     */
    protected function buildFilename(OfficialLeaveLetter $letter): string
    {
        $request = $letter->leaveRequest;
        $employee = $request?->employee;
        $typeCode = match ($letter->document_type) {
            OfficialLeaveLetter::TYPE_APPROVAL => 'approval',
            OfficialLeaveLetter::TYPE_REJECTION => 'rejection',
            OfficialLeaveLetter::TYPE_CANCELLATION => 'cancellation',
            default => 'letter',
        };
        $name = $employee?->initials ?? 'EMP';
        $ref = $request?->ref ?? 'UNKNOWN';
        $version = $letter->document_version;

        return "{$typeCode}-{$ref}-{$name}-v{$version}.pdf";
    }
}
