<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaseController;
use App\Http\Controllers\Api\DelegationController;
use App\Http\Controllers\Api\EligibilityController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeavePaymentController;
use App\Http\Controllers\Api\LeavePlanController;
use App\Http\Controllers\Api\LeaveReturnController;
use App\Http\Controllers\Api\LeaveWaiverController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OfficialLetterController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\PermissionAuditController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\ReportingLineController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — stateful Sanctum tokens. Everything except login/logout is
| behind `auth:sanctum`; module/action grants mirror the frontend permission
| model (see PermissionService + seed role_permissions matrix).
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateMe']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Organization (read-only reference data)
    Route::prefix('org')->group(function () {
        Route::get('zones', [OrganizationController::class, 'zones']);
        Route::get('departments', [OrganizationController::class, 'departments']);
        Route::get('positions', [OrganizationController::class, 'positions']);
        Route::get('approval-roles', [OrganizationController::class, 'approvalRoles']);
        // Zones and departments are real org units, not fixtures: full CRUD so an
        // admin can correct the structure instead of living with a frozen seed.
        Route::post('zones', [OrganizationController::class, 'storeZone'])->middleware('permission:admin.create');
        Route::match(['put', 'patch'], 'zones/{zone}', [OrganizationController::class, 'updateZone'])->middleware('permission:admin.update');
        Route::delete('zones/{zone}', [OrganizationController::class, 'destroyZone'])->middleware('permission:admin.delete');
        Route::post('departments', [OrganizationController::class, 'storeDepartment'])->middleware('permission:admin.create');
        Route::match(['put', 'patch'], 'departments/{department}', [OrganizationController::class, 'updateDepartment'])->middleware('permission:admin.update');
        Route::delete('departments/{department}', [OrganizationController::class, 'destroyDepartment'])->middleware('permission:admin.delete');
        // Head appointments: the one way scoped structural grants (head of d1,
        // zonal head of z4) enter the system — assigning always names a unit.
        Route::match(['put', 'patch'], 'departments/{department}/head', [OrganizationController::class, 'setDepartmentHead'])->middleware('permission:admin.update');
        Route::delete('departments/{department}/head', [OrganizationController::class, 'clearDepartmentHead'])->middleware('permission:admin.update');
        Route::match(['put', 'patch'], 'zones/{zone}/head', [OrganizationController::class, 'setZoneHead'])->middleware('permission:admin.update');
        Route::delete('zones/{zone}/head', [OrganizationController::class, 'clearZoneHead'])->middleware('permission:admin.update');
        Route::get('employees', [OrganizationController::class, 'employees'])->middleware('permission:staff.view_own');
        Route::post('employees', [OrganizationController::class, 'store'])->middleware('permission:admin.create');
        Route::patch('employees/{employee}', [OrganizationController::class, 'update'])->middleware('permission:admin.update');
        Route::delete('employees/{employee}', [OrganizationController::class, 'destroy'])
        ->middleware('permission:employees.force_delete');
        // Single-grant toggles: safe for scoped structural grants, which a full
        // role_ids replacement would silently widen.
        Route::post('employees/{employee}/roles/{role}', [OrganizationController::class, 'grantRole'])->middleware('permission:admin.update');
        Route::delete('employees/{employee}/roles/{role}', [OrganizationController::class, 'revokeRole'])->middleware('permission:admin.update');
        Route::post('positions', [OrganizationController::class, 'storePosition'])->middleware('permission:admin.create');
        Route::match(['put', 'patch'], 'positions/{position}', [OrganizationController::class, 'updatePosition'])->middleware('permission:admin.update');
        Route::delete('positions/{position}', [OrganizationController::class, 'destroyPosition'])->middleware('permission:admin.delete');
        Route::get('tree', [OrganizationController::class, 'orgTree'])->middleware('permission:staff.view_own');
        // Phase 8 — Reporting-line CRUD (separate from registration)
        Route::get('employees/{employee}/reporting-lines', [ReportingLineController::class, 'index'])->middleware('permission:admin.view');
        Route::post('employees/{employee}/reporting-lines', [ReportingLineController::class, 'store'])->middleware('permission:admin.create');
        Route::patch('reporting-lines/{reportingLine}', [ReportingLineController::class, 'update'])->middleware('permission:admin.update');
        Route::delete('reporting-lines/{reportingLine}', [ReportingLineController::class, 'destroy'])->middleware('permission:admin.update');
        Route::get('employees/{employee}/supervisor', [ReportingLineController::class, 'supervisor'])->middleware('permission:staff.view_own');
        Route::get('employees/{employee}/supervisees', [ReportingLineController::class, 'supervisees'])->middleware('permission:admin.view');
        Route::get('reporting-lines', [ReportingLineController::class, 'all'])->middleware('permission:admin.view');
        // --- Divisions and Independent Units (Part 3 & 4) ---
        Route::get('divisions', [OrganizationController::class, 'indexDivisions'])->middleware('permission:admin.view');
        Route::post('divisions', [OrganizationController::class, 'storeDivision'])->middleware('permission:admin.create');
        Route::get('divisions/{division}', [OrganizationController::class, 'showDivision'])->middleware('permission:admin.view');
        Route::patch('divisions/{division}', [OrganizationController::class, 'updateDivision'])->middleware('permission:admin.update');
        Route::delete('divisions/{division}', [OrganizationController::class, 'destroyDivision'])->middleware('permission:admin.delete');
        Route::post('divisions/{division}/head', [OrganizationController::class, 'assignDivisionHead'])->middleware('permission:admin.update');
        Route::get('divisions/{division}/employees', [OrganizationController::class, 'getDivisionEmployees'])->middleware('permission:admin.view');
        Route::get('divisions/{division}/head', [OrganizationController::class, 'getDivisionHead'])->middleware('permission:admin.view');
        // Units
        Route::get('units', [OrganizationController::class, 'indexUnits'])->middleware('permission:admin.view');
        Route::post('units', [OrganizationController::class, 'storeUnit'])->middleware('permission:admin.create');
        Route::get('units/{unit}', [OrganizationController::class, 'showUnit'])->middleware('permission:admin.view');
        Route::patch('units/{unit}', [OrganizationController::class, 'updateUnit'])->middleware('permission:admin.update');
        Route::delete('units/{unit}', [OrganizationController::class, 'destroyUnit'])->middleware('permission:admin.delete');
        Route::post('units/{unit}/head', [OrganizationController::class, 'assignUnitHead'])->middleware('permission:admin.update');
        Route::get('units/{unit}/employees', [OrganizationController::class, 'getUnitEmployees'])->middleware('permission:admin.view');
        Route::get('units/{unit}/head', [OrganizationController::class, 'getUnitHead'])->middleware('permission:admin.view');
    });

    // Leave types, policies, balances
    Route::prefix('leave')->group(function () {
        Route::post('types', [LeaveController::class, 'storeLeaveType'])->middleware('permission:admin.create');
        Route::patch('types/{leaveType}', [LeaveController::class, 'updateLeaveType'])->middleware('permission:admin.update');
        // Unused-type deletion (admin.delete — same family as the zones,
        // departments and staff destroys). Referenced types refuse with a
        // count; a free type takes policies + workflows with it.
        Route::delete('types/{leaveType}', [LeaveController::class, 'destroyLeaveType'])->middleware('permission:admin.delete');
        Route::get('types', [LeaveController::class, 'leaveTypes']);
        Route::get('policies/current', [LeaveController::class, 'currentPolicies']);
        Route::get('policies/{leaveTypeId}/history', [LeaveController::class, 'policyHistory']);
        Route::get('balance', [LeaveController::class, 'myBalance']);
        Route::get('balance/{leaveTypeId}', [LeaveController::class, 'balanceFor']);
        Route::get('tips/{leaveTypeId}', [LeaveController::class, 'tips']);
    });

    // Phase 2 — leave waivers and deferrals
    Route::prefix('leave-waivers')->group(function () {
        Route::get('/', [LeaveWaiverController::class, 'index']);
        Route::post('/', [LeaveWaiverController::class, 'store'])->middleware('permission:leave_waivers.create_own');
        Route::get('{leaveWaiver}', [LeaveWaiverController::class, 'show']);
        Route::post('{leaveWaiver}/submit', [LeaveWaiverController::class, 'submit'])->middleware('permission:leave_waivers.create_own');
        Route::post('{leaveWaiver}/review', [LeaveWaiverController::class, 'review'])->middleware('permission:leave_waivers.review');
        Route::post('{leaveWaiver}/approve', [LeaveWaiverController::class, 'approve'])->middleware('permission:leave_waivers.approve');
        Route::post('{leaveWaiver}/reject', [LeaveWaiverController::class, 'reject'])->middleware('permission:leave_waivers.reject');
        Route::post('{leaveWaiver}/cancel', [LeaveWaiverController::class, 'cancel'])->middleware('permission:leave_waivers.create_own');
    })->whereNumber('leaveWaiver');

    // Phase 2 — almanac leave planning
    Route::prefix('leave-plans')->group(function () {
        Route::get('/', [LeavePlanController::class, 'index']);
        Route::post('/', [LeavePlanController::class, 'store'])->middleware('permission:leave_plans.create_own');
        Route::get('calendar', [LeavePlanController::class, 'calendar']);
        Route::get('{leavePlan}', [LeavePlanController::class, 'show']);
        Route::post('{leavePlan}/propose', [LeavePlanController::class, 'propose'])->middleware('permission:leave_plans.create_own');
        Route::post('{leavePlan}/select', [LeavePlanController::class, 'select'])->middleware('permission:leave_plans.select_own');
        Route::post('{leavePlan}/confirm', [LeavePlanController::class, 'confirm']);
        Route::post('{leavePlan}/convert', [LeavePlanController::class, 'convert'])->middleware('permission:leave_plans.select_own');
        Route::post('{leavePlan}/cancel', [LeavePlanController::class, 'cancel'])->middleware('permission:leave_plans.select_own');
    })->whereNumber('leavePlan');

    // Eligibility recognition — per employee and org-wide (department/zone)
    Route::prefix('eligibility')->group(function () {
        Route::get('/', [EligibilityController::class, 'index']);
    });

    // Requests
    Route::prefix('requests')->group(function () {
        Route::get('/', [RequestController::class, 'index'])->middleware('permission:requests.view');
        Route::post('/', [RequestController::class, 'store'])->middleware('permission:requests.create');
        Route::match(['put', 'patch'], '{leaveRequest}', [RequestController::class, 'update'])->middleware('permission:requests.edit_own');
        Route::get('inbox', [RequestController::class, 'myInbox']);
        // Scope-gated chain preview for the new-request form; resolution
        // happens in the controller, so no permission code is required here.
        Route::post('preview', [RequestController::class, 'previewChain']);
        Route::post('{leaveRequest}/documents', [RequestController::class, 'attachDocument'])->middleware('permission:documents.create');
        // Gated on the ordinary read code, not documents.view_medical_documents.
        // Requiring the medical gate for every document locked out the uploader's
        // own ordinary files; the sensitivity decision belongs to
        // LeaveDocumentPolicy, which knows each document's type and owner.
        Route::get('{leaveRequest}/documents', [RequestController::class, 'documents'])->middleware('permission:documents.view');
        Route::get('{leaveRequest}/documents/{document}', [RequestController::class, 'showDocument'])->middleware('permission:documents.view');
        // Streams the bytes. Declared after the metadata route so "documents/{document}"
        // does not swallow it, and gated on the same code - the policy does the
        // per-document medical/ownership decision in both cases.
        Route::get('{leaveRequest}/documents/{document}/download', [RequestController::class, 'downloadDocument'])->middleware('permission:documents.view');
        Route::delete('{leaveRequest}/documents/{document}', [RequestController::class, 'deleteDocument'])->middleware('permission:documents.delete');
        Route::get('{leaveRequest}', [RequestController::class, 'show']);
        Route::get('{leaveRequest}/workflow', [RequestController::class, 'workflow']);
        Route::post('{leaveRequest}/submit', [RequestController::class, 'submit'])->middleware('permission:requests.submit');
        Route::post('{leaveRequest}/resubmit', [RequestController::class, 'resubmit'])->middleware('permission:requests.submit');
        // decide() carries no route code on purpose: the required code depends
        // on the decision (approve/reject/return) and is checked inside
        // RequestController::decide alongside assignment ownership.
        Route::post('{leaveRequest}/decide', [RequestController::class, 'decide']);
        Route::post('{leaveRequest}/withdraw', [RequestController::class, 'withdraw'])->middleware('permission:requests.withdraw');
        // No permission middleware by design (same as {leaveRequest}/decide
        // below): deletion is ownership-intrinsic — applicant-only AND
        // draft-only, both enforced in the controller. Submitted requests
        // have no delete route at all.
        // Force delete: a separate capability from ordinary administration,
        // requires a reason and preserves documents/payment cases.
        Route::delete('{leaveRequest}/force', [RequestController::class, 'forceDelete'])
            ->middleware('permission:leave_requests.force_delete');
        Route::delete('{leaveRequest}', [RequestController::class, 'destroy']);
    })->whereNumber('leaveRequest');

    // Phase 3 — return to work and late-return handling
    Route::prefix('leave-requests')->group(function () {
        Route::post('{leaveRequest}/return', [LeaveReturnController::class, 'report'])->middleware('permission:leave_returns.report_own');
        Route::get('{leaveRequest}/return', [LeaveReturnController::class, 'show']);
    })->whereNumber('leaveRequest');

    Route::prefix('leave-returns')->group(function () {
        Route::post('{leaveReturn}/explanation', [LeaveReturnController::class, 'explanation'])->middleware('permission:leave_returns.report_own');
        Route::post('{leaveReturn}/review', [LeaveReturnController::class, 'review'])->middleware('permission:leave_returns.review');
        Route::post('{leaveReturn}/close', [LeaveReturnController::class, 'close'])->middleware('permission:leave_returns.close');
    })->whereNumber('leaveReturn');

    // Phase 5 — leave payments (encashment / purchase), HR review and Accounting settlement
    Route::prefix('leave-payments')->group(function () {
        Route::get('/', [LeavePaymentController::class, 'index']);
        Route::post('/', [LeavePaymentController::class, 'store'])->middleware('permission:leave_payments.create');
        Route::get('{leavePaymentCase}', [LeavePaymentController::class, 'show'])->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/evaluate', [LeavePaymentController::class, 'evaluate'])->middleware('permission:leave_payments.create')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/submit', [LeavePaymentController::class, 'submit'])->middleware('permission:leave_payments.create')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/approve', [LeavePaymentController::class, 'approve'])->middleware('permission:leave_payments.approve')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/reject', [LeavePaymentController::class, 'reject'])->middleware('permission:leave_payments.reject')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/send-to-accounting', [LeavePaymentController::class, 'sendToAccounting'])->middleware('permission:leave_payments.review')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/receive', [LeavePaymentController::class, 'receive'])->middleware('permission:leave_payments.receive')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/paid', [LeavePaymentController::class, 'paid'])->middleware('permission:leave_payments.process')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/reject-payment', [LeavePaymentController::class, 'rejectPayment'])->middleware('permission:leave_payments.process')->whereNumber('leavePaymentCase');
        Route::post('{leavePaymentCase}/cancel', [LeavePaymentController::class, 'cancel'])->middleware('permission:leave_payments.cancel')->whereNumber('leavePaymentCase');
    });

    // Phase 6 — official leave letters, digital signatures, and QR verification
    Route::prefix('leave-requests')->group(function () {
        Route::prefix('{leaveRequest}/letters')->group(function () {
            Route::get('/', [OfficialLetterController::class, 'index']);
            Route::post('generate', [OfficialLetterController::class, 'generateApproval'])->middleware('permission:leave_documents.generate');
            Route::post('generate-rejection', [OfficialLetterController::class, 'generateRejection'])->middleware('permission:leave_documents.generate');
            Route::post('generate-cancellation', [OfficialLetterController::class, 'generateCancellation'])->middleware('permission:leave_documents.generate');
            Route::get('{letter}', [OfficialLetterController::class, 'show'])->whereNumber('letter');
            Route::get('{letter}/download', [OfficialLetterController::class, 'download'])->whereNumber('letter');
            Route::get('{letter}/temporary-url', [OfficialLetterController::class, 'temporaryUrl'])->middleware('permission:leave_documents.download_hr')->whereNumber('letter');
            Route::post('{letter}/revoke', [OfficialLetterController::class, 'revoke'])->middleware('permission:leave_documents.revoke')->whereNumber('letter');
            Route::get('verify-signatures', [OfficialLetterController::class, 'verifySignature']);
        })->whereNumber('leaveRequest');
    });

    // Public QR verification (no auth)
    Route::get('verify/letter/{qrToken}', [OfficialLetterController::class, 'verify']);

    // Workflow definitions. Reads and writes carry both the legacy policies.*
    // codes and the dedicated approval_workflows.* codes (defense in depth):
    // the holder sets are identical, so stacking changes no outcome, but both
    // families stay genuinely enforced and neither rots into a dead switch.
    Route::prefix('workflows')->middleware(['permission:policies.view', 'permission:approval_workflows.view'])->group(function () {
        Route::get('/', [WorkflowController::class, 'index']);
        Route::get('{workflowDefinition}', [WorkflowController::class, 'show']);
        Route::post('/', [WorkflowController::class, 'store'])->middleware(['permission:policies.edit_policy', 'permission:approval_workflows.create']);
        Route::match(['put', 'patch'], '{workflowDefinition}', [WorkflowController::class, 'update'])->middleware(['permission:policies.edit_policy', 'permission:approval_workflows.update']);
        // Deletion is an update-class operation on definitions (see
        // WorkflowController::destroy): same stacked holder set as the write
        // routes above — no dedicated delete code enters the catalogue. The
        // controller refuses definitions carrying history and the type's
        // last active chain.
        Route::delete('{workflowDefinition}', [WorkflowController::class, 'destroy'])->middleware(['permission:policies.edit_policy', 'permission:approval_workflows.update']);
    });

    // Delegations — a user manages the roles they hold
    Route::prefix('delegations')->group(function () {
        Route::get('/', [DelegationController::class, 'index'])->middleware('permission:delegations.view');
        Route::post('/', [DelegationController::class, 'store'])->middleware('permission:delegations.create');
        Route::post('{delegation}/revoke', [DelegationController::class, 'revoke'])->middleware('permission:delegations.update');
    });

    // Court cases + handovers (incl. the final-approval gate). Every route
    // carries its case_handovers.* code on top of the policy checks inside
    // (recipient/scope rules still apply) so handover access is visible in
    // the matrix and can never be reached through admin.* by accident.
    Route::prefix('cases')->group(function () {
        Route::get('/', [CaseController::class, 'index'])->middleware('permission:case_handovers.view');
        Route::get('can-final-approve', [CaseController::class, 'canFinalApprove'])->middleware('permission:case_handovers.view');
    });
    Route::prefix('handovers')->group(function () {
        Route::get('{leaveRequest}', [CaseController::class, 'handovers'])->middleware('permission:case_handovers.view')->whereNumber('leaveRequest');
        Route::post('{leaveRequest}/propose', [CaseController::class, 'propose'])->middleware('permission:case_handovers.create')->whereNumber('leaveRequest');
        Route::post('{handover}/accept', [CaseController::class, 'accept'])->middleware('permission:case_handovers.accept');
        Route::post('{handover}/reject', [CaseController::class, 'reject'])->middleware('permission:case_handovers.reject');
        Route::post('{handover}/complete', [CaseController::class, 'complete'])->middleware('permission:case_handovers.complete');
    });

    // Notifications — read your own; manage your own delivery preferences.
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->middleware('permission:notifications.view_own');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->middleware('permission:notifications.view_own');
        Route::get('preferences', [NotificationPreferenceController::class, 'index'])->middleware('permission:notifications.manage_preferences');
        Route::patch('preferences', [NotificationPreferenceController::class, 'update'])->middleware('permission:notifications.manage_preferences');
        Route::post('{notification}/read', [NotificationController::class, 'markRead'])->middleware('permission:notifications.view_own');
    });

    // Audit ledger + chain verification (admin)
    Route::prefix('audit')->middleware('permission:admin.view')->group(function () {
        Route::get('/', [AuditController::class, 'index']);
        Route::get('verify', [AuditController::class, 'verify']);
    });

    // Reports (Phase 7 — on-screen paginated + exports + audit)
    Route::prefix('reports')->group(function () {
        // Report catalogue + filters
        Route::get('{type}/filters', [ReportController::class, 'filters']);

        // On-screen paginated reports (require appropriate permission)
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('leave_requests', [ReportController::class, 'index']);
            Route::get('leave_plans', [ReportController::class, 'index']);
            Route::get('waivers', [ReportController::class, 'index']);
            Route::get('returns', [ReportController::class, 'index']);
            Route::get('policies', [ReportController::class, 'index']);
            Route::get('approval_performance', [ReportController::class, 'index']);
        });

        Route::middleware('permission:reports.view_own')->group(function () {
            Route::get('employee_history', [ReportController::class, 'index']);
        });

        Route::middleware('permission:reports.view_financial')->group(function () {
            Route::get('balance', [ReportController::class, 'index']);
            Route::get('payments', [ReportController::class, 'index']);
        });

        Route::middleware('permission:reports.view_sensitive')->group(function () {
            Route::get('documents', [ReportController::class, 'index']);
        });

        Route::middleware('permission:audit_logs.view')->group(function () {
            Route::get('audit', [ReportController::class, 'index']);
        });

        // Legacy endpoints (kept for backward compat)
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('zone', [ReportController::class, 'byZone']);
            Route::get('department', [ReportController::class, 'byDepartment']);
            Route::get('type', [ReportController::class, 'byType']);
            Route::get('bottleneck', [ReportController::class, 'bottleneck']);
            Route::get('upcoming', [ReportController::class, 'upcoming']);
            Route::get('monthly', [ReportController::class, 'monthly']);
            Route::get('forecast', [ReportController::class, 'forecast']);
            Route::get('staffing', [ReportController::class, 'staffing']);
        });

        Route::middleware(['permission:reports.view', 'permission:reports.view_gender_reports'])->group(function () {
            Route::get('gender', [ReportController::class, 'gender']);
        });

        // Drill-down: every summary card opens a filtered detail table.
        // Totals and rows share builders (ReportDetailService), filters stay
        // in the URL, and pagination links carry them via appends().
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('summary', [ReportController::class, 'summary']);
            Route::get('approved-days/details', [ReportController::class, 'approvedDaysDetails']);
            Route::get('pending/details', [ReportController::class, 'pendingDetails']);
            Route::get('staff/details', [ReportController::class, 'staffDetails']);
            Route::get('requests/details', [ReportController::class, 'requestsDetails']);
            Route::get('leave-usage-by-zone/{zone}/details', [ReportController::class, 'zoneDetails']);
            Route::get('leave-types/{leaveType}/details', [ReportController::class, 'leaveTypeDetails']);
            Route::get('status/{status}/details', [ReportController::class, 'statusDetails']);
        });

        Route::middleware(['permission:reports.view', 'permission:reports.view_gender_reports'])->group(function () {
            Route::get('gender/{gender}/details', [ReportController::class, 'genderDetails']);
        });

        // Payment Overview: authorized inside the controller (financial
        // reviewers and cross-zone payment holders; employees reach only
        // their own history), because no single catalog code fits every
        // audience. Row shaping is audience-aware (see the PaymentCase*
        // resources); every access is audit-logged.
        Route::prefix('payment')->group(function () {
            Route::get('summary', [ReportController::class, 'paymentSummary']);
            Route::get('paid', [ReportController::class, 'paidCases']);
            Route::get('eligible-unpaid', [ReportController::class, 'eligibleUnpaidCases']);
            Route::get('without-payment-request', [ReportController::class, 'withoutRequestCases']);
            Route::get('leave-taken-unpaid', [ReportController::class, 'leaveTakenUnpaidCases']);
            Route::get('eligible-no-leave', [ReportController::class, 'eligibleNoLeaveCases']);
            Route::get('pending-hr', [ReportController::class, 'pendingHrCases']);
            Route::get('pending-accounting', [ReportController::class, 'pendingAccountingCases']);
            Route::get('processing', [ReportController::class, 'processingCases']);
            Route::get('rejected', [ReportController::class, 'rejectedCases']);
            Route::get('employees/{employee}/history', [ReportController::class, 'employeePaymentHistory']);
        });

        // Export management
        Route::middleware('permission:reports.export')->group(function () {
            Route::post('exports', [ReportController::class, 'createExport']);
            Route::get('exports', [ReportController::class, 'listExports']);
            Route::get('exports/{export}', [ReportController::class, 'showExport'])->whereNumber('export');
            Route::get('exports/{export}/download', [ReportController::class, 'downloadExport'])->whereNumber('export');
            Route::post('exports/{export}/cancel', [ReportController::class, 'cancelExport'])->whereNumber('export');
        });

        Route::middleware('permission:report_exports.download')->group(function () {
            Route::get('exports/{export}/download', [ReportController::class, 'downloadExport'])->whereNumber('export');
        });

        Route::middleware('permission:report_exports.cancel')->group(function () {
            Route::post('exports/{export}/cancel', [ReportController::class, 'cancelExport'])->whereNumber('export');
        });

        Route::middleware('permission:admin.update')->group(function () {
            Route::post('exports/expire', [ReportController::class, 'expireExports']);
        });

        // Audit
        Route::middleware('permission:audit_logs.view')->group(function () {
            Route::get('audit', [ReportController::class, 'auditSearch']);
            Route::get('audit/verify', [ReportController::class, 'auditVerify']);
            Route::get('audit/verify/{entityType}/{entityId}', [ReportController::class, 'auditVerifyEntity']);
            Route::get('audit/statistics', [ReportController::class, 'auditStatistics']);
            Route::get('audit/entity-types', [ReportController::class, 'auditEntityTypes']);
            Route::get('audit/actors', [ReportController::class, 'auditActors']);
        });
    });

    // Policy authoring. Creating a policy is the catalogued policies.create
    // action, so it is gated by that code rather than by policies.edit_policy:
    // gating creation on the edit code made policies.create unreachable and the
    // two codes indistinguishable.
    Route::post('policies', [PolicyController::class, 'store'])->middleware('permission:policies.create');

    // RBAC admin (settings createRole/updateRole/deleteRole)
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:admin.view');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:admin.view');
        // Read-only permission audit (matrix coverage, holders, drift).
        Route::get('permissions/audit', [PermissionAuditController::class, 'index'])->middleware('permission:admin.view');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:admin.create');
        Route::match(['put', 'patch'], '{role}', [RoleController::class, 'update'])->middleware('permission:admin.update');
        Route::delete('{role}', [RoleController::class, 'destroy'])->middleware('permission:admin.delete');
        // Archiving is reversible; restoring puts back exactly the permissions the role
        // held when it was archived. It takes admin.update, not admin.create:
        // restore is the inverse of a configuration change, not the minting of a
        // new role, and requiring create meant an org that could edit roles could
        // not bring an archived one back.
        Route::post('{role}/restore', [RoleController::class, 'restore'])->middleware('permission:admin.update');
    });
});
