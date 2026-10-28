<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Report authorization service (Phase 7).
 *
 * Centralises all authorization decisions for reports and exports.
 * Enforces the principle of least privilege and scope-based access.
 */
class ReportAuthorizationService
{
    /**
     * Permission codes for report access.
     */
    public const PERM_VIEW_OWN = 'reports.view_own';

    public const PERM_VIEW_DEPARTMENT = 'reports.view_department';

    public const PERM_VIEW_ZONE = 'reports.view_zone';

    public const PERM_VIEW_ORG = 'reports.view_organisation';

    public const PERM_EXPORT = 'reports.export';

    public const PERM_VIEW_AUDIT = 'reports.view_audit';

    public const PERM_VIEW_FINANCIAL = 'reports.view_financial';

    public const PERM_VIEW_SENSITIVE = 'reports.view_sensitive';

    public const PERM_EXPORT_DOWNLOAD = 'report_exports.download';

    public const PERM_EXPORT_CANCEL = 'report_exports.cancel';

    public const PERM_AUDIT_VIEW = 'audit_logs.view';

    /**
     * Check if a user can view reports of a given type.
     *
     * @param  array{can_view_all_zones:bool,allowed_zone_ids:array<int,string>,allowed_department_ids:array<int,string>,own_records_only:bool}  $scope
     */
    public function canViewReports(User $user, string $reportType, array $scope): bool
    {
        $perm = app(PermissionService::class);

        // Audit logs are special
        if ($reportType === 'audit') {
            return $perm->can($user, 'audit_logs', 'view');
        }

        // Financial reports (payments, balance) require financial permission
        if (in_array($reportType, ['payments', 'balance'], true)) {
            if (! $perm->can($user, 'reports', 'view_financial')) {
                return false;
            }
        }

        // Sensitive reports (employee_history with all statuses, documents) require sensitive permission
        if (in_array($reportType, ['employee_history', 'documents'], true)) {
            if (! $perm->can($user, 'reports', 'view_sensitive')) {
                return false;
            }
        }

        // Organisation-wide view
        if ($scope['can_view_all_zones']) {
            return $perm->can($user, 'reports', 'view_organisation');
        }

        // Department scope
        if ($scope['allowed_department_ids'] !== []) {
            return $perm->can($user, 'reports', 'view_department');
        }

        // Zone scope
        if ($scope['allowed_zone_ids'] !== []) {
            return $perm->can($user, 'reports', 'view_zone');
        }

        // Own records only
        if ($scope['own_records_only']) {
            return $perm->can($user, 'reports', 'view_own');
        }

        return false;
    }

    /**
     * Check if a user can export a report.
     */
    public function canExport(User $user, array $scope): bool
    {
        $perm = app(PermissionService::class);

        if (! $perm->can($user, 'reports', 'export')) {
            return false;
        }

        // Export requires at least department scope
        if ($scope['can_view_all_zones']) {
            return true;
        }

        if ($scope['allowed_department_ids'] !== [] || $scope['allowed_zone_ids'] !== []) {
            return true;
        }

        // Own records only can export their own data
        return $scope['own_records_only'];
    }

    /**
     * Check if a user can download a specific export.
     */
    public function canDownloadExport(Employee $downloader, ReportExport $export): bool
    {
        // Requester can always download their own
        if ($downloader->id === $export->requested_by) {
            return true;
        }

        $perm = app(PermissionService::class);

        if (! $perm->can($downloader->user, 'report_exports', 'download')) {
            return false;
        }

        $scope = app(AccessScopeService::class)->forUser($downloader->user, AccessScopeService::REPORTS);

        if ($scope['can_view_all_zones']) {
            return true;
        }

        if (! $scope['has_zone_scope']) {
            return false;
        }

        $requester = Employee::find($export->requested_by);
        if (! $requester) {
            return false;
        }

        return in_array($requester->zone_id, $scope['allowed_zone_ids'], true)
            || in_array($requester->department_id, $scope['allowed_department_ids'], true);
    }

    /**
     * Check if a user can cancel an export.
     */
    public function canCancelExport(Employee $canceller, ReportExport $export): bool
    {
        // Requester can always cancel their own
        if ($canceller->id === $export->requested_by) {
            return true;
        }

        $perm = app(PermissionService::class);

        if (! $perm->can($canceller->user, 'report_exports', 'cancel')) {
            return false;
        }

        $scope = app(AccessScopeService::class)->forUser($canceller->user, AccessScopeService::REPORTS);

        if ($scope['can_view_all_zones']) {
            return true;
        }

        if (! $scope['has_zone_scope']) {
            return false;
        }

        $requester = Employee::find($export->requested_by);
        if (! $requester) {
            return false;
        }

        return in_array($requester->zone_id, $scope['allowed_zone_ids'], true)
            || in_array($requester->department_id, $scope['allowed_department_ids'], true);
    }

    /**
     * Get the list of report types a user can access.
     */
    public function accessibleReportTypes(User $user): array
    {
        $scope = app(AccessScopeService::class)->forUser($user, AccessScopeService::REPORTS);
        $allTypes = [
            'leave_requests',
            'employee_history',
            'leave_plans',
            'waivers',
            'returns',
            'balance',
            'payments',
            'policies',
            'approval_performance',
            'documents',
            'audit',
        ];

        return array_filter($allTypes, fn ($type) => $this->canViewReports($user, $type, $scope->toArray()));
    }

    /**
     * Throw if user cannot view a report type.
     */
    public function assertCanViewReports(User $user, string $reportType): void
    {
        $scope = app(AccessScopeService::class)->forUser($user, AccessScopeService::REPORTS);

        if (! $this->canViewReports($user, $reportType, $scope->toArray())) {
            throw ValidationException::withMessages([
                'authorization' => "You do not have permission to view {$reportType} reports.",
            ]);
        }
    }

    /**
     * Throw if user cannot export.
     */
    public function assertCanExport(User $user): void
    {
        $scope = app(AccessScopeService::class)->forUser($user, AccessScopeService::REPORTS);

        if (! $this->canExport($user, $scope->toArray())) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to export reports.',
            ]);
        }
    }

    /**
     * Throw if user cannot download export.
     */
    public function assertCanDownloadExport(Employee $downloader, ReportExport $export): void
    {
        if (! $this->canDownloadExport($downloader, $export)) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to download this export.',
            ]);
        }
    }

    /**
     * Throw if user cannot cancel export. (ReportController::cancelExport
     * calls this; it was missing, which made every cancel a fatal error.)
     */
    public function assertCanCancelExport(Employee $canceller, ReportExport $export): void
    {
        if (! $this->canCancelExport($canceller, $export)) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to cancel this export.',
            ]);
        }
    }

    /**
     * Payment Overview audience gate: financial reviewers (HR-side money
     * visibility) or cross-zone payment holders (Accounting). Employees
     * reach only their own history, which is authorized separately per
     * employee — this gate is for the aggregate and category surfaces.
     */
    public function canViewPaymentReports(User $user): bool
    {
        return app(PermissionService::class)->can($user, 'reports', 'view_financial')
            || app(PermissionService::class)->can($user, 'leave_payments', 'view_cross_zone');
    }

    /**
     * Throw if user cannot view payment reports.
     */
    public function assertCanViewPaymentReports(User $user): void
    {
        if (! $this->canViewPaymentReports($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to view payment reports.',
            ]);
        }
    }
}
