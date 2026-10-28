<?php

namespace App\Policies;

use App\Models\LeaveDocument;
use App\Models\User;
use App\Services\PermissionService;

class LeaveDocumentPolicy
{
    /**
     * Document types carrying medical/birth data. These require the explicit
     * documents.view_medical_documents code on top of request visibility - the
     * grant is never implied by zone scope.
     *
     * These are stored NORMALISED (lowercased, non-alphanumerics stripped), and
     * compared that way, because the values actually written to leave_documents are
     * display strings - the upload UI sends "Medical certificate", "Antenatal record",
     * "Birth certificate" - while an earlier version of this list used snake_case
     * codes. Exact string matching against the codes matched none of the real rows,
     * so every medical certificate was served to anyone who could see the request:
     * documents.view_medical_documents was, in effect, still unenforced.
     */
    protected const SENSITIVE_TYPES = [
        'medical certificate',
        'medical cert',
        'medical_cert',
        'antenatal record',
        'antenatal_record',
        'birth certificate',
        'birth cert',
        'birth_doc',
    ];

    public function __construct(protected PermissionService $permissions) {}

    public function view(User $user, LeaveDocument $document): bool
    {
        if ($document->uploaded_by_user_id !== null && $document->uploaded_by_user_id === $user->id) {
            return true;
        }

        if ($this->isSensitive($document) && ! $this->permissions->can($user, 'documents', 'view_medical_documents')) {
            return false;
        }

        $request = $document->request;

        if (! $request) {
            return false;
        }

        return app(LeaveRequestPolicy::class)->view($user, $request);
    }

    /**
     * Removing a document is not the same permission as reading one.
     *
     * The route already requires documents.delete, so that code is the floor.
     * On top of it the caller must be able to act on the underlying request,
     * which stops a documents.delete holder who has no business touching that
     * request from deleting a colleague's medical record by guessing an id.
     * The uploader may always retract their own upload.
     */
    public function delete(User $user, LeaveDocument $document): bool
    {
        if (! $this->permissions->can($user, 'documents', 'delete')) {
            return false;
        }

        if ($document->uploaded_by_user_id !== null && $document->uploaded_by_user_id === $user->id) {
            return true;
        }

        $request = $document->request;

        if (! $request) {
            return false;
        }

        return app(LeaveRequestPolicy::class)->update($user, $request);
    }

    protected function isSensitive(LeaveDocument $document): bool
    {
        return in_array($this->normaliseType($document->document_type), $this->sensitiveSet(), true);
    }

    /**
     * @return list<string>
     */
    protected function sensitiveSet(): array
    {
        static $set = null;

        return $set ??= array_map(
            fn (string $type): string => $this->normaliseType($type),
            self::SENSITIVE_TYPES,
        );
    }

    /**
     * Fold case and punctuation so "Medical certificate", "medical_certificate"
     * and "MedicalCertificate" are one value. See SENSITIVE_TYPES for why this is
     * load-bearing rather than cosmetic.
     */
    protected function normaliseType(string $type): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $type));
    }
}
