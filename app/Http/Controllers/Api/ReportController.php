<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeavePaymentCase;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\AuditReportService;
use App\Services\AuditService;
use App\Services\EmployeeLeavePaymentReportService;
use App\Services\LeavePaymentReportService;
use App\Services\LeaveReportFilters;
use App\Services\PaymentSummaryService;
use App\Services\PermissionService;
use App\Services\ReportAuthorizationService;
use App\Services\ReportDetailService;
use App\Services\ReportExportService;
use App\Services\ReportFilterService;
use App\Services\ReportQueryService;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Report API endpoints (Phase 7).
 *
 * On-screen paginated reports:
 *   GET  /reports/{type}                    list with filters + pagination
 *   GET  /reports/{type}/filters            available filter fields
 *
 * Export management:
 *   POST /reports/exports                   create export (async)
 *   GET  /reports/exports                   list exports
 *   GET  /reports/exports/{export}          export details
 *   GET  /reports/exports/{export}/download download file (signed URL)
 *   POST /reports/exports/{export}/cancel   cancel export
 *   POST /reports/exports/expire            expire old exports (admin)
 *
 * Audit:
 *   GET  /audit/logs                        search audit logs
 *   GET  /audit/logs/verify                 verify chain
 *   GET  /audit/logs/verify/{type}/{id}     verify entity chain
 *   GET  /audit/logs/statistics             audit statistics
 *   GET  /audit/logs/entity-types           list entity types
 *   GET  /audit/logs/actors                 list actors
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportQueryService $queries,
        protected ReportFilterService $filters,
        protected ReportExportService $exports,
        protected ReportAuthorizationService $auth,
        protected ReportDetailService $details,
        protected LeavePaymentReportService $paymentReports,
        protected PaymentSummaryService $paymentSummary,
        protected EmployeeLeavePaymentReportService $pairs,
        protected PermissionService $permissions,
        protected AuditService $audit,
    ) {}

    // ============================================================
    // On-screen Reports
    // ============================================================

    /**
     * List available filter fields for a report type.
     */
    public function filters(string $type): JsonResponse
    {
        $available = $this->filters->availableFilters();

        if (! isset($available[$type])) {
            return response()->json(['message' => "Unknown report type: {$type}"], 404);
        }

        return response()->json([
            'data' => [
                'report_type' => $type,
                'filters' => $available[$type],
                'default_range' => $this->filters->defaultRange(),
            ],
        ]);
    }

    /**
     * Paginated on-screen report.
     */
    public function index(Request $request, string $type): JsonResponse
    {
        $user = $request->user();

        // Check authorization
        $scope = app(AccessScopeService::class)->forUser($user, AccessScopeService::REPORTS);
        $this->auth->assertCanViewReports($user, $type);

        // Get and validate filters
        $filters = $this->filters->fromRequest($type, $request);
        $this->filters->validate($type, $filters);

        // Get query
        $query = $this->getQuery($type, $user, $filters);

        // Paginate
        $perPage = min((int) $request->input('per_page', 50), 200);
        $data = $query->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * Get the query builder for a report type.
     */
    protected function getQuery(string $type, User $user, array $filters): Builder
    {
        return match ($type) {
            'leave_requests' => $this->queries->leaveRequests($user, $filters),
            'employee_history' => $this->queries->employeeHistory($user, $filters),
            'leave_plans' => $this->queries->leavePlans($user, $filters),
            'waivers' => $this->queries->waivers($user, $filters),
            'returns' => $this->queries->returns($user, $filters),
            'balance' => $this->queries->balances($user, $filters),
            'payments' => $this->queries->payments($user, $filters),
            'policies' => $this->queries->policies($user, $filters),
            'approval_performance' => $this->queries->approvalPerformance($user, $filters),
            'documents' => $this->queries->documents($user, $filters),
            'audit' => $this->queries->audit($user, $filters),
            default => throw ValidationException::withMessages([
                'type' => "Unknown report type: {$type}",
            ]),
        };
    }

    // ============================================================
    // Export Management
    // ============================================================

    /**
     * Create a new report export.
     */
    public function createExport(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json(['message' => 'Only employees can create exports.'], 403);
        }

        $this->auth->assertCanExport($user);

        $data = $request->validate([
            'report_type' => ['required', 'string', 'in:'.implode(',', ReportExport::TYPES)],
            'format' => ['required', 'string', 'in:csv,pdf'],
            'filters' => ['nullable', 'array'],
        ]);

        $filters = $data['filters'] ?? [];
        $this->filters->validate($data['report_type'], $filters);

        $export = $this->exports->create(
            $employee,
            $data['report_type'],
            $data['format'],
            $filters,
            $user,
        );

        return response()->json(['data' => $this->exportResource($export)], 201);
    }

    /**
     * List exports for the current user (or all if HR).
     */
    public function listExports(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $query = ReportExport::query()->with('requester')
            ->orderByDesc('created_at');

        // Scope
        if (! $this->canViewAllExports($user)) {
            $query->where('requested_by', $employee?->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->input('report_type'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $data = $query->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ],
        ]);
    }

    /**
     * View a single export.
     */
    public function showExport(Request $request, ReportExport $export): JsonResponse
    {
        $this->authorizeExportAccess($request->user(), $export);

        return response()->json(['data' => $this->exportResource($export)]);
    }

    /**
     * Download an export (returns signed URL).
     */
    public function downloadExport(Request $request, ReportExport $export): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $this->auth->assertCanDownloadExport($employee, $export);

        $result = $this->exports->download($export, $employee);

        return response()->json(['data' => $result]);
    }

    /**
     * Cancel an export.
     */
    public function cancelExport(Request $request, ReportExport $export): JsonResponse
    {
        $employee = $request->user()->employee;

        $this->auth->assertCanCancelExport($employee, $export);

        $export = $this->exports->cancel($export, $employee);

        return response()->json(['data' => $this->exportResource($export)]);
    }

    /**
     * Expire old exports (admin only).
     */
    public function expireExports(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->employee || ! app(PermissionService::class)->can($user, 'admin', 'update')) {
            return response()->json(['message' => 'Admin permission required.'], 403);
        }

        $count = $this->exports->expireOld();

        return response()->json(['data' => ['expired_count' => $count]]);
    }

    // ============================================================
    // Legacy Report Endpoints (kept for backward compatibility)
    // ============================================================

    protected function range(Request $request): array
    {
        $defaultFrom = now()->startOfYear()->toDateString();
        $defaultTo = now()->addMonths(3)->endOfMonth()->toDateString();

        return [
            $request->input('from', $defaultFrom),
            $request->input('to', $defaultTo),
        ];
    }

    public function byZone(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->usageByZone($from, $to, $request->user())]);
    }

    public function byDepartment(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->usageByDepartment($from, $to, $request->user())]);
    }

    public function byType(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->usageByType($from, $to, $request->user())]);
    }

    public function gender(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->genderReport($from, $to, $request->user())]);
    }

    public function bottleneck(Request $request): JsonResponse
    {
        return response()->json(['data' => app(ReportService::class)->bottleneck(null, $request->user())]);
    }

    public function upcoming(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->upcoming($from, $to, $request->user())]);
    }

    public function monthly(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->monthly($from, $to, $request->user())]);
    }

    public function forecast(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->forecast($from, $to, $request->user())]);
    }

    public function staffing(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => app(ReportService::class)->staffingCoverage($from, $to, $request->user())]);
    }

    // ============================================================
    // Drill-down Endpoints (summary cards + filtered detail tables)
    // ============================================================

    /**
     * Office summary: the five cards plus the zone/type/status aggregates,
     * all computed from the same builders the detail endpoints paginate.
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = new LeaveReportFilters($request->query());
        $overview = $this->details->overview(
            $request->user(),
            $filters,
            $this->permissions->can($request->user(), 'reports', 'view_gender_reports')
        );

        return response()->json([
            'data' => $overview,
            'meta' => ['filters' => $filters->all()],
        ]);
    }

    /**
     * Paginate a detail builder with a whitelisted sort, shaping rows for
     * display. Pagination links carry the request query string so filters
     * survive page navigation.
     */
    protected function paginatedDetails(
        Request $request,
        LeaveReportFilters $filters,
        Builder $query,
        callable $shape,
        array $sorts,
        string $defaultSort,
        string $defaultDirection = 'desc'
    ): JsonResponse {
        [$column, $direction] = $filters->sort($sorts, $defaultSort, $defaultDirection);
        $page = $query->orderBy($column, $direction)->paginate($filters->perPage());
        $page->appends($request->query());

        return response()->json([
            'data' => collect($page->items())->map($shape)->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'filters' => $filters->all(),
            ],
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ]);
    }

    public function approvedDaysDetails(Request $request): JsonResponse
    {
        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->approvedDays($request->user(), $filters),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    public function pendingDetails(Request $request): JsonResponse
    {
        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->pending($request->user(), $filters),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    public function staffDetails(Request $request): JsonResponse
    {
        $filters = new LeaveReportFilters($request->query());
        $canSeeGender = $this->permissions->can($request->user(), 'reports', 'view_gender_reports');

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->staff($request->user(), $filters),
            fn ($e) => ReportDetailService::shapeStaffRow($e, $canSeeGender),
            LeaveReportFilters::STAFF_SORTS,
            'full_name',
            'asc'
        );
    }

    public function genderDetails(Request $request, string $gender): JsonResponse
    {
        Validator::make(
            ['gender' => $gender],
            ['gender' => 'required|in:M,F']
        )->validate();

        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->genderGroup($request->user(), $filters, $gender),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    public function requestsDetails(Request $request): JsonResponse
    {
        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->loggedRequests($request->user(), $filters),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    public function zoneDetails(Request $request, string $zone): JsonResponse
    {
        Validator::make(
            ['zone' => $zone],
            ['zone' => 'required|string|exists:zones,id']
        )->validate();

        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->zoneUsage($request->user(), $filters, $zone),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    public function leaveTypeDetails(Request $request, string $leaveType): JsonResponse
    {
        Validator::make(
            ['leave_type' => $leaveType],
            ['leave_type' => 'required|string|exists:leave_types,id']
        )->validate();

        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->typeUsage($request->user(), $filters, $leaveType),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    public function statusDetails(Request $request, string $status): JsonResponse
    {
        Validator::make(
            ['status' => $status],
            ['status' => 'required|string|in:draft,submitted,under_review,approved,returned,returned_for_correction,rejected,withdrawn,completed']
        )->validate();

        $filters = new LeaveReportFilters($request->query());

        return $this->paginatedDetails(
            $request,
            $filters,
            $this->details->statusGroup($request->user(), $filters, $status),
            [ReportDetailService::class, 'shapeRequestRow'],
            LeaveReportFilters::REQUEST_SORTS,
            'start_date'
        );
    }

    // ============================================================
    // Payment Overview Endpoints
    // ============================================================

    /**
     * Record financial/sensitive report access. Payment visibility touches
     * money and eligibility reasoning, so every summary, category and
     * history read leaves an audit row with the audience and filters.
     */
    protected function auditPaymentAccess(User $user, string $surface, LeaveReportFilters $filters): void
    {
        $this->audit->append('report', 'payment-overview', 'accessed', $user->id, [
            'surface' => $surface,
            'audience' => $this->paymentReports->audienceFor($user),
            'filters' => $filters->all(),
        ]);
    }

    /**
     * Shape one payment case for the caller's audience.
     *
     * @return array<string,mixed>
     */
    protected function shapePaymentCase(User $user, LeavePaymentCase $case): array
    {
        $resource = $this->paymentReports->resourceFor($user);

        return $resource::make($case)->toArray(request());
    }

    /**
     * Paginate a payment-case builder with audience shaping.
     */
    protected function paginatedPayments(Request $request, LeaveReportFilters $filters, Builder $query): JsonResponse
    {
        $user = $request->user();
        [$column, $direction] = $filters->sort(LeaveReportFilters::PAYMENT_SORTS, 'created_at', 'desc');
        $page = $query->orderBy($column, $direction)->paginate($filters->perPage());
        $page->appends($request->query());

        return response()->json([
            'data' => collect($page->items())->map(fn ($c) => $this->shapePaymentCase($user, $c))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'filters' => $filters->all(),
                'audience' => $this->paymentReports->audienceFor($user),
            ],
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Paginate pair-member keys with display rows loaded for the page only.
     * Totals come from full member-key counts (id-keyed, never full rows).
     *
     * @param  array<int,array{employee_id:string,leave_type_id:string}>  $members
     */
    protected function paginatedPairs(Request $request, LeaveReportFilters $filters, array $members): JsonResponse
    {
        $user = $request->user();
        $perPage = $filters->perPage();
        $pageNum = max(1, (int) ($filters->get('page') ?? $request->input('page', 1)));
        $total = count($members);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $pageNum = min($pageNum, $lastPage);
        $slice = array_slice($members, ($pageNum - 1) * $perPage, $perPage);

        $rows = $this->paymentReports->pairRows($slice, $user, $filters);

        $paginator = new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $pageNum,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $from = $total === 0 ? null : ($pageNum - 1) * $perPage + 1;
        $to = $total === 0 ? null : min($total, $pageNum * $perPage);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $pageNum,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
                'filters' => $filters->all(),
                'audience' => $this->paymentReports->audienceFor($user),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($lastPage),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function paymentSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->auth->assertCanViewPaymentReports($user);

        $filters = new LeaveReportFilters($request->query());
        $paymentType = $filters->get('payment_type', LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT);
        $summary = $this->paymentSummary->summary($user, $filters, $paymentType);

        $this->auditPaymentAccess($user, 'payment-summary', $filters);

        return response()->json([
            'data' => $summary,
            'meta' => ['filters' => $filters->all(), 'audience' => $this->paymentReports->audienceFor($user)],
        ]);
    }

    /**
     * Case-anchored payment categories share one implementation: the same
     * builder the summary counts, paginated with audience shaping.
     */
    protected function paymentCategory(Request $request, string $category, string $surface): JsonResponse
    {
        $user = $request->user();
        $this->auth->assertCanViewPaymentReports($user);

        $filters = new LeaveReportFilters($request->query());
        $categories = $this->paymentReports->caseCategories();

        if (! isset($categories[$category])) {
            throw ValidationException::withMessages(['category' => "Unknown payment category: {$category}."]);
        }

        $this->auditPaymentAccess($user, $surface, $filters);

        return $this->paginatedPayments($request, $filters, $categories[$category]($user, $filters));
    }

    public function paidCases(Request $request): JsonResponse
    {
        return $this->paymentCategory($request, 'paid', 'payment-paid');
    }

    public function pendingHrCases(Request $request): JsonResponse
    {
        return $this->paymentCategory($request, 'pending_hr', 'payment-pending-hr');
    }

    public function pendingAccountingCases(Request $request): JsonResponse
    {
        return $this->paymentCategory($request, 'pending_accounting', 'payment-pending-accounting');
    }

    public function processingCases(Request $request): JsonResponse
    {
        return $this->paymentCategory($request, 'processing', 'payment-processing');
    }

    public function rejectedCases(Request $request): JsonResponse
    {
        return $this->paymentCategory($request, 'rejected', 'payment-rejected');
    }

    /**
     * Pair-anchored categories share one implementation: member keys from
     * the same analysis the summary counts, display rows for the page only.
     *
     * @param  'without_request'|'eligible_no_leave'|'leave_taken_unpaid'|'eligible_unpaid'  $bucket
     */
    protected function paymentPairCategory(Request $request, string $bucket, string $surface): JsonResponse
    {
        $user = $request->user();
        $this->auth->assertCanViewPaymentReports($user);

        $filters = new LeaveReportFilters($request->query());
        $paymentType = $filters->get('payment_type', LeavePaymentCase::PAYMENT_TYPE_ENCASHMENT);
        $subset = $this->paymentReports->audienceFor($user) === LeavePaymentReportService::AUDIENCE_ACCOUNTING
            ? LeavePaymentReportService::ACCOUNTING_STATUSES
            : null;
        $analysis = $this->pairs->analyze($user, $filters, $paymentType, $subset);

        $this->auditPaymentAccess($user, $surface, $filters);

        return $this->paginatedPairs($request, $filters, $analysis['buckets'][$bucket] ?? []);
    }

    public function eligibleUnpaidCases(Request $request): JsonResponse
    {
        return $this->paymentPairCategory($request, 'eligible_unpaid', 'payment-eligible-unpaid');
    }

    public function withoutRequestCases(Request $request): JsonResponse
    {
        return $this->paymentPairCategory($request, 'without_request', 'payment-without-request');
    }

    public function leaveTakenUnpaidCases(Request $request): JsonResponse
    {
        return $this->paymentPairCategory($request, 'leave_taken_unpaid', 'payment-leave-taken-unpaid');
    }

    public function eligibleNoLeaveCases(Request $request): JsonResponse
    {
        return $this->paymentPairCategory($request, 'eligible_no_leave', 'payment-eligible-no-leave');
    }

    public function employeePaymentHistory(Request $request, string $employee): JsonResponse
    {
        $user = $request->user();

        Validator::make(
            ['employee' => $employee],
            ['employee' => 'required|string|exists:employees,id']
        )->validate();

        if (! $this->paymentReports->mayViewHistory($user, $employee)) {
            return response()->json(['message' => 'You may only view your own payment history.'], 403);
        }

        $filters = new LeaveReportFilters($request->query());

        $this->auditPaymentAccess($user, 'payment-history', $filters);

        return $this->paginatedPayments($request, $filters, $this->paymentReports->historyQuery($user, $employee, $filters));
    }

    // ============================================================
    // Audit Endpoints
    // ============================================================

    /**
     * Search audit logs.
     */
    public function auditSearch(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! app(PermissionService::class)->can($user, 'audit_logs', 'view')) {
            return response()->json(['message' => 'Permission denied.'], 403);
        }

        $filters = $request->only([
            'date_from', 'date_to', 'entity_type', 'entity_id',
            'action', 'actor_user_id', 'search',
        ]);

        $audit = app(AuditReportService::class);
        $query = $audit->search($user, $filters);

        $perPage = min((int) $request->input('per_page', 50), 200);
        $data = $query->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * Verify entire audit chain.
     */
    public function auditVerify(): JsonResponse
    {
        $audit = app(AuditReportService::class);

        return response()->json(['data' => $audit->verifyChain()]);
    }

    /**
     * Verify entity audit chain.
     */
    public function auditVerifyEntity(string $entityType, string $entityId): JsonResponse
    {
        $audit = app(AuditReportService::class);

        return response()->json(['data' => $audit->verifyEntityChain($entityType, $entityId)]);
    }

    /**
     * Audit statistics.
     */
    public function auditStatistics(Request $request): JsonResponse
    {
        $audit = app(AuditReportService::class);

        return response()->json(['data' => $audit->statistics(
            $request->input('date_from'),
            $request->input('date_to'),
        )]);
    }

    /**
     * List entity types with audit logs.
     */
    public function auditEntityTypes(): JsonResponse
    {
        $audit = app(AuditReportService::class);

        return response()->json(['data' => $audit->entityTypes()]);
    }

    /**
     * List actors in audit logs.
     */
    public function auditActors(): JsonResponse
    {
        $audit = app(AuditReportService::class);

        return response()->json(['data' => $audit->actors()]);
    }

    protected function canViewAllExports(User $user): bool
    {
        $perm = app(PermissionService::class);

        return $perm->can($user, 'report_exports', 'download')
            && app(AccessScopeService::class)->forUser($user, AccessScopeService::REPORTS)->canViewAllZones;
    }

    protected function authorizeExportAccess(User $user, ReportExport $export): void
    {
        $employee = $user->employee;

        if (! $employee) {
            throw ValidationException::withMessages(['authorization' => 'Only employees can access exports.']);
        }

        if ($employee->id === $export->requested_by) {
            return;
        }

        if (! $this->canViewAllExports($user)) {
            throw ValidationException::withMessages(['authorization' => 'You cannot view this export.']);
        }
    }

    protected function exportResource(ReportExport $export): array
    {
        return [
            'id' => $export->id,
            'report_type' => $export->report_type,
            'format' => $export->format,
            'status' => $export->status,
            'filters' => $export->filters_json,
            'scope_snapshot' => $export->scope_snapshot_json,
            'file_path' => $export->file_path,
            'file_hash' => $export->file_hash,
            'row_count' => $export->row_count,
            'column_count' => $export->column_count,
            'generated_at' => $export->generated_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'cancelled_at' => $export->cancelled_at?->toIso8601String(),
            'failure_reason' => $export->failure_reason,
            'created_at' => $export->created_at?->toIso8601String(),
            'updated_at' => $export->updated_at?->toIso8601String(),
            'is_downloadable' => $export->isDownloadable(),
            'requester' => $export->requester?->only('id', 'full_name'),
        ];
    }
}
