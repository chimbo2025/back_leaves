<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Blade;

/**
 * Leave document renderer (Phase 6).
 *
 * Renders official leave letters to PDF using Blade templates. The templates
 * are stored in resources/views/official-letters/ and can be overridden per
 * organisation without code changes.
 *
 * The renderer uses Dompdf (or the configured PDF engine) under the hood.
 * If Dompdf is not installed, it falls back to a basic HTML string for
 * testing purposes.
 */
class LeaveDocumentRenderer
{
    public function __construct(
        protected SecureDocumentService $secure,
    ) {}

    /**
     * Render an official letter to PDF bytes.
     *
     * @param  array{request: LeaveRequest, policy_snapshot: array, approval_snapshot: array, generator: Employee, template_version: int, document_version: int, qr_token: string}  $data
     */
    public function render(string $documentType, array $data): string
    {
        $html = $this->renderHtml($documentType, $data);

        // Try to use Dompdf if available, otherwise return HTML for testing.
        if (class_exists(Dompdf::class)) {
            $pdf = new Dompdf;
            $pdf->loadHtml($html);
            $pdf->setPaper('A4', 'portrait');
            $pdf->render();

            return $pdf->output();
        }

        // Fallback for environments without Dompdf (tests).
        return $html;
    }

    /**
     * Render the HTML for the given document type.
     */
    protected function renderHtml(string $documentType, array $data): string
    {
        $view = match ($documentType) {
            OfficialLeaveLetter::TYPE_APPROVAL => 'official-letters.approval',
            OfficialLeaveLetter::TYPE_REJECTION => 'official-letters.rejection',
            OfficialLeaveLetter::TYPE_CANCELLATION => 'official-letters.cancellation',
            default => 'official-letters.approval',
        };

        $data['orgName'] = config('leave.org_name', 'Directorate of Public Prosecutions');
        $data['orgSubtitle'] = config('leave.org_subtitle', 'Zanzibar, Tanzania');
        $data['brandColor'] = config('leave.brand_color', '#1a3c5e');
        $data['logoPath'] = config('leave.org_logo_path', null);
        $data['documentTypeLabel'] = match ($documentType) {
            'approval' => 'Official Leave Approval Letter',
            'rejection' => 'Official Leave Rejection Letter',
            'cancellation' => 'Official Leave Cancellation Letter',
            default => 'Official Leave Letter',
        };
        $data['leaveTypeLabel'] = $data['request']->leaveType?->name ?? $data['request']->leave_type_id;
        $data['documentReference'] = 'DPP-LEAVE-' . $data['request']->ref;
        $finalAuth = collect($data['approval_snapshot'] ?? [])
            ->firstWhere('role', 'final_authority');
        $data['finalAuthority'] = $finalAuth ? [
            'full_name' => $finalAuth['assignee'],
            'decision' => collect($finalAuth['actions'] ?? [])
                ->firstWhere('decision', 'approved')?['decision'] ?? 'approved',
            'signed_at' => collect($finalAuth['actions'] ?? [])
                ->firstWhere('decision', 'approved')?['acted_at'],
        ] : null;

        return Blade::render($view, $data);
    }

    /**
     * Render a preview HTML (for UI display without PDF generation).
     */
    public function renderPreview(string $documentType, array $data): string
    {
        return $this->renderHtml($documentType, $data);
    }
}
