<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ReportExport;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Report export service (Phase 7).
 *
 * Handles the full lifecycle of a report export:
 * - Create export record with filters and scope snapshot
 * - Generate CSV/PDF content
 * - Store in private storage
 * - Generate temporary download URLs
 * - Handle expiry, cancellation, and cleanup
 * - Audit events
 */
class ReportExportService
{
    public const EXPORT_TTL_DAYS = 7;

    public const CHUNK_SIZE = 1000;

    public function __construct(
        protected ReportQueryService $queries,
        protected ReportFilterService $filters,
        protected LeaveDocumentRenderer $renderer,
        protected SecureDocumentService $secure,
        protected AuditService $audit,
    ) {}

    // ============================================================
    // Public API
    // ============================================================

    /**
     * Create and queue a new report export.
     */
    public function create(
        Employee $requester,
        string $reportType,
        string $format,
        array $filters,
        ?User $user = null,
    ): ReportExport {
        $scopeSnapshot = $this->captureScope($requester);

        $export = ReportExport::create([
            'report_type' => $reportType,
            'requested_by' => $requester->id,
            'filters_json' => $filters,
            'scope_snapshot_json' => $scopeSnapshot,
            'format' => $format,
            'status' => ReportExport::STATUS_QUEUED,
            'file_disk' => 'local',
            'expires_at' => now()->addDays(self::EXPORT_TTL_DAYS),
        ]);

        if (app()->runningUnitTests()) {
            $this->generate($export);
        } else {
            $this->generate($export);
        }

        return $export;
    }

    /**
     * Generate the export file (called by queue worker or synchronously in tests).
     */
    public function generate(ReportExport $export): void
    {
        $export->update([
            'status' => ReportExport::STATUS_PROCESSING,
            'generated_at' => now(),
        ]);

        // audit_logs.actor_user_id references users.id, while requested_by is
        // an employee id — passing it straight through broke every export
        // with an FK violation. Resolve the login id once for both branches.
        $actorUserId = Employee::where('id', $export->requested_by)->value('user_id');

        try {
            $data = $this->fetchData($export);
            $filePath = $this->writeFile($export, $data);
            $fileHash = hash_file('sha256', storage_path('app/private/'.$filePath));

            $export->update([
                'status' => ReportExport::STATUS_COMPLETED,
                'file_path' => $filePath,
                'file_hash' => $fileHash,
                'row_count' => count($data),
                'column_count' => $data ? count($data[0]) : 0,
            ]);

            $this->audit->append('report_export', (string) $export->id, 'export.completed', $actorUserId, [
                'report_type' => $export->report_type,
                'format' => $export->format,
                'row_count' => $export->row_count,
                'file_hash' => $fileHash,
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => ReportExport::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
            ]);

            $this->audit->append('report_export', (string) $export->id, 'export.failed', $actorUserId, [
                'report_type' => $export->report_type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Download an export (creates temporary signed URL).
     *
     * @return array{url:string,expires_in:int}
     */
    public function download(ReportExport $export, Employee $downloader): array
    {
        if (! $export->isDownloadable()) {
            throw ValidationException::withMessages([
                'export' => 'This export is not available for download.',
            ]);
        }

        $this->authorizeDownload($downloader, $export);

        $url = Storage::disk($export->file_disk)->temporaryUrl(
            $export->file_path,
            now()->addMinutes(SecureDocumentService::DOWNLOAD_TTL)
        );

        $this->audit->append('report_export', (string) $export->id, 'export.downloaded', $downloader->user_id, [
            'report_type' => $export->report_type,
            'format' => $export->format,
        ]);

        return [
            'url' => $url,
            'expires_in' => SecureDocumentService::DOWNLOAD_TTL * 60,
        ];
    }

    /**
     * Cancel a pending/processing export.
     */
    public function cancel(ReportExport $export, Employee $canceller): ReportExport
    {
        if ($export->isTerminal()) {
            return $export->fresh();
        }

        $export->update([
            'status' => ReportExport::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        if ($export->file_path && Storage::disk($export->file_disk)->exists($export->file_path)) {
            Storage::disk($export->file_disk)->delete($export->file_path);
            $export->update(['file_path' => null, 'file_hash' => null]);
        }

        $this->audit->append('report_export', (string) $export->id, 'export.cancelled', $canceller->user_id, [
            'report_type' => $export->report_type,
        ]);

        return $export->fresh();
    }

    /**
     * Expire old exports (scheduled job).
     */
    public function expireOld(): int
    {
        $expired = ReportExport::where('status', ReportExport::STATUS_COMPLETED)
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $export) {
            $export->update([
                'status' => ReportExport::STATUS_EXPIRED,
            ]);

            if ($export->file_path && Storage::disk($export->file_disk)->exists($export->file_path)) {
                Storage::disk($export->file_disk)->delete($export->file_path);
                $export->update(['file_path' => null, 'file_hash' => null]);
            }

            $this->audit->append('report_export', (string) $export->id, 'export.expired', null, [
                'report_type' => $export->report_type,
            ]);

            $count++;
        }

        return $count;
    }

    // ============================================================
    // Internal
    // ============================================================

    protected function fetchData(ReportExport $export): array
    {
        $user = User::whereHas('employee', fn ($q) => $q->where('id', $export->requested_by))->firstOrFail();
        $filters = $export->filters_json ?? [];

        $filters = app(ReportFilterService::class)->normalise($export->report_type, $filters);

        return match ($export->report_type) {
            ReportExport::TYPE_LEAVE_REQUESTS => $this->queries->leaveRequests($user, $filters)->get()->toArray(),
            ReportExport::TYPE_EMPLOYEE_HISTORY => $this->queries->employeeHistory($user, $filters)->get()->toArray(),
            ReportExport::TYPE_LEAVE_PLANS => $this->queries->leavePlans($user, $filters)->get()->toArray(),
            ReportExport::TYPE_WAIVERS => $this->queries->waivers($user, $filters)->get()->toArray(),
            ReportExport::TYPE_RETURNS => $this->queries->returns($user, $filters)->get()->toArray(),
            ReportExport::TYPE_BALANCE => $this->queries->getBalanceData($user, $filters),
            ReportExport::TYPE_PAYMENTS => $this->queries->payments($user, $filters)->get()->toArray(),
            ReportExport::TYPE_POLICIES => $this->queries->policies($user, $filters)->get()->toArray(),
            ReportExport::TYPE_APPROVAL_PERFORMANCE => $this->queries->approvalPerformance($user, $filters)->get()->toArray(),
            ReportExport::TYPE_DOCUMENTS => $this->queries->documents($user, $filters)->get()->toArray(),
            ReportExport::TYPE_AUDIT => $this->queries->audit($user, $filters)->get()->toArray(),
            default => throw ValidationException::withMessages([
                'report_type' => "Unknown report type: {$export->report_type}",
            ]),
        };
    }

    protected function writeFile(ReportExport $export, array $data): string
    {
        if ($data === []) {
            $data = [[]];
        }

        $fileName = "exports/{$export->report_type}_{$export->id}.{$export->format}";
        $content = $export->format === ReportExport::FORMAT_CSV
            ? $this->toCsv($data)
            : $this->toPdf($export->report_type, $data);

        Storage::disk('local')->put($fileName, $content);

        return $fileName;
    }

    protected function toCsv(array $data): string
    {
        if ($data === [] || $data === [[]]) {
            return '';
        }

        $headers = array_keys($data[0]);
        $rows = [];

        $rows[] = implode(',', array_map(fn ($h) => '"'.str_replace('"', '""', $h).'"', $headers));

        foreach ($data as $row) {
            $rows[] = implode(',', array_map(function ($v) {
                if (is_null($v)) {
                    return '';
                }
                // Relation payloads arrive as nested arrays (employee,
                // leaveType, …); encode rather than crash on (string) cast.
                $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v;

                return '"'.str_replace('"', '""', $v).'"';
            }, array_values($row)));
        }

        return implode("\n", $rows);
    }

    protected function toPdf(string $reportType, array $data): string
    {
        $html = $this->renderer->renderPreview('report', [
            'report_type' => $reportType,
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
            'title' => $this->reportTitle($reportType),
        ]);

        if (class_exists(Dompdf::class)) {
            $pdf = new Dompdf;
            $pdf->loadHtml($html);
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return $pdf->output();
        }

        return $html;
    }

    protected function reportTitle(string $reportType): string
    {
        return match ($reportType) {
            ReportExport::TYPE_LEAVE_REQUESTS => 'Leave Requests Report',
            ReportExport::TYPE_EMPLOYEE_HISTORY => 'Employee Leave History Report',
            ReportExport::TYPE_LEAVE_PLANS => 'Leave Plans Report',
            ReportExport::TYPE_WAIVERS => 'Waivers Report',
            ReportExport::TYPE_RETURNS => 'Return & Late-Return Cases Report',
            ReportExport::TYPE_BALANCE => 'Leave Balances Report',
            ReportExport::TYPE_PAYMENTS => 'Payment Cases Report',
            ReportExport::TYPE_POLICIES => 'Leave Policies Report',
            ReportExport::TYPE_APPROVAL_PERFORMANCE => 'Approval Performance Report',
            ReportExport::TYPE_DOCUMENTS => 'Official Documents Report',
            ReportExport::TYPE_AUDIT => 'Audit History Report',
            default => 'Report',
        };
    }

    protected function captureScope(Employee $requester): array
    {
        // The link lives on employees.user_id, not on users (there is no
        // users.employee_id column); an employee without a login snapshots
        // as own-records-only instead of failing the whole export.
        $user = User::whereHas('employee', fn ($q) => $q->where('id', $requester->id))->first();

        if ($user === null) {
            return [
                'can_view_all_zones' => false,
                'allowed_zone_ids' => [],
                'allowed_department_ids' => [],
                'own_records_only' => true,
            ];
        }

        $scope = app(AccessScopeService::class)->forUser(
            $user,
            AccessScopeService::REPORTS
        );

        return [
            'can_view_all_zones' => $scope->canViewAllZones,
            'allowed_zone_ids' => $scope->allowedZoneIds,
            'allowed_department_ids' => $scope->allowedDepartmentIds,
            'own_records_only' => $scope->ownRecordsOnly,
        ];
    }

    protected function authorizeDownload(Employee $downloader, ReportExport $export): void
    {
        $perm = app(PermissionService::class);

        if ($downloader->id === $export->requested_by) {
            return;
        }

        if (! $perm->can($downloader->user, 'reports', 'export')) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to download this export.',
            ]);
        }

        $scope = app(AccessScopeService::class)->forUser($downloader->user, AccessScopeService::REPORTS);
        if ($scope->canViewAllZones) {
            return;
        }

        $requester = Employee::find($export->requested_by);
        if (! $requester) {
            throw ValidationException::withMessages([
                'authorization' => 'Cannot verify scope for this export.',
            ]);
        }

        if (! $scope->hasZoneScope()) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have scope to download exports for other employees.',
            ]);
        }

        if (! in_array($requester->zone_id, $scope->allowedZoneIds, true)
            && ! in_array($requester->department_id, $scope->allowedDepartmentIds, true)) {
            throw ValidationException::withMessages([
                'authorization' => 'This export is outside your authorised zone/department.',
            ]);
        }
    }
}
