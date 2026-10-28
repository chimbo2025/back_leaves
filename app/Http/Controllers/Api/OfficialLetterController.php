<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\OfficialLeaveLetter;
use App\Services\DocumentVerificationService;
use App\Services\LeaveApprovalSignatureService;
use App\Services\OfficialLeaveLetterService;
use App\Services\SecureDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Official leave letter endpoints (Phase 6).
 *
 *   GET  /leave-requests/{leaveRequest}/letters                list all versions
 *   POST /leave-requests/{leaveRequest}/letters/generate       generate approval letter
 *   POST /leave-requests/{leaveRequest}/letters/generate-rejection  generate rejection letter
 *   POST /leave-requests/{leaveRequest}/letters/generate-cancellation generate cancellation letter
 *   GET  /leave-requests/{leaveRequest}/letters/{letter}       view letter metadata
 *   GET  /leave-requests/{leaveRequest}/letters/{letter}/download  download letter (private storage)
 *   POST /leave-requests/{leaveRequest}/letters/{letter}/revoke  revoke letter (HR only)
 *
 * Public verification:
 *   GET  /verify/letter/{qrToken}     QR verification (no auth)
 */
class OfficialLetterController extends Controller
{
    public function __construct(
        protected OfficialLeaveLetterService $letters,
        protected SecureDocumentService $secure,
        protected DocumentVerificationService $verification,
        protected LeaveApprovalSignatureService $signatureService,
    ) {}

    /**
     * List all letter versions for a request.
     */
    public function index(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        $letters = OfficialLeaveLetter::where('leave_request_id', $leaveRequest->id)
            ->orderBy('document_type')
            ->orderBy('document_version')
            ->get()
            ->map(fn (OfficialLeaveLetter $l) => $this->resource($l));

        return response()->json(['data' => $letters]);
    }

    /**
     * Generate the official approval letter.
     */
    public function generateApproval(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('generate', OfficialLeaveLetter::class);

        $letter = $this->letters->generateApprovalLetter($leaveRequest, $request->user()->employee, [
            'template_version' => $request->integer('template_version'),
            'force_regenerate' => $request->boolean('force_regenerate'),
        ]);

        return response()->json(['data' => $this->resource($letter)], 201);
    }

    /**
     * Generate the official rejection letter.
     */
    public function generateRejection(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('generate', OfficialLeaveLetter::class);

        $letter = $this->letters->generateRejectionLetter($leaveRequest, $request->user()->employee, [
            'template_version' => $request->integer('template_version'),
            'force_regenerate' => $request->boolean('force_regenerate'),
        ]);

        return response()->json(['data' => $this->resource($letter)], 201);
    }

    /**
     * Generate the official cancellation letter.
     */
    public function generateCancellation(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('generate', OfficialLeaveLetter::class);

        $letter = $this->letters->generateCancellationLetter($leaveRequest, $request->user()->employee, [
            'template_version' => $request->integer('template_version'),
            'force_regenerate' => $request->boolean('force_regenerate'),
        ]);

        return response()->json(['data' => $this->resource($letter)], 201);
    }

    /**
     * View a specific letter's metadata.
     */
    public function show(Request $request, LeaveRequest $leaveRequest, OfficialLeaveLetter $letter): JsonResponse
    {
        $this->authorize('view', $letter);

        return response()->json(['data' => $this->resource($letter)]);
    }

    /**
     * Download a letter (private storage, audit logged).
     */
    public function download(Request $request, LeaveRequest $leaveRequest, OfficialLeaveLetter $letter)
    {
        // The SecureDocumentService enforces scope and permissions.
        return $this->secure->download($letter, $request->user(), $request->ip());
    }

    /**
     * Get a temporary signed URL for direct download.
     */
    public function temporaryUrl(Request $request, LeaveRequest $leaveRequest, OfficialLeaveLetter $letter): JsonResponse
    {
        $this->authorize('downloadHr', $letter);

        $url = $this->secure->temporaryUrl($letter, $request->user());

        return response()->json(['url' => $url, 'expires_in' => SecureDocumentService::DOWNLOAD_TTL]);
    }

    /**
     * Revoke a letter (HR only).
     */
    public function revoke(Request $request, LeaveRequest $leaveRequest, OfficialLeaveLetter $letter): JsonResponse
    {
        $this->authorize('revoke', $letter);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $letter = $this->letters->revoke($letter, $request->user()->employee, $data['reason']);

        return response()->json(['data' => $this->resource($letter)]);
    }

    /**
     * Public QR verification endpoint (no auth).
     */
    public function verify(string $qrToken): JsonResponse
    {
        $result = $this->verification->verify($qrToken);

        if (! $result['valid']) {
            return response()->json([
                'valid' => false,
                'error' => $result['error'],
            ], 404);
        }

        return response()->json($result);
    }

    /**
     * Verify a signature for an approval action.
     */
    public function verifySignature(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        $signatures = $this->signatureService->getForRequest($leaveRequest);

        $results = $signatures->map(function ($sig) {
            return [
                'signature_id' => $sig->id,
                'approval_action_id' => $sig->approval_action_id,
                'actor' => $sig->actor?->full_name,
                'decision' => $sig->approval_action,
                'signed_at' => $sig->signed_at?->toIso8601String(),
                'valid' => $this->signatureService->verify($sig),
                'signature_type' => $sig->signature_type,
                'ip_address' => $sig->ip_address,
            ];
        })->values();

        return response()->json(['data' => $results]);
    }

    /**
     * Resource representation.
     */
    protected function resource(OfficialLeaveLetter $letter): array
    {
        $letter->loadMissing(['leaveRequest.employee', 'leaveRequest.leaveType', 'generator']);

        return [
            'id' => $letter->id,
            'leave_request_id' => $letter->leave_request_id,
            'document_type' => $letter->document_type,
            'document_version' => $letter->document_version,
            'template_version' => $letter->template_version,
            'content_hash' => $letter->content_hash,
            'storage_path' => $letter->storage_path,
            'storage_disk' => $letter->storage_disk,
            'generated_by' => $letter->generator?->full_name,
            'generated_at' => $letter->generated_at?->toIso8601String(),
            'revoked_at' => $letter->revoked_at?->toIso8601String(),
            'revoked_by' => $letter->revoker?->full_name,
            'revocation_reason' => $letter->revocation_reason,
            'qr_token' => $letter->qr_token,
            'is_revoked' => $letter->isRevoked(),
            'verification_url' => url('/verify/letter/'.$letter->qr_token),
        ];
    }
}
