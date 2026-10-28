<?php

/*
|--------------------------------------------------------------------------
| Permission enforcement map
|--------------------------------------------------------------------------
|
| Where each catalogued permission code is actually consulted, and which codes
| exist purely as frontend capabilities.
|
| This file is the single source of truth for both DatabaseSeeder and the
| lifecycle migration. It deliberately lives in config/ rather than app/:
| PermissionCatalogIntegrityTest scans app/ and routes/ for quoted
| "module.action" literals to decide whether a code is enforced, so a map kept
| inside app/ would make every code look wired and defeat the test.
|
| The enforcement_reference recorded against a permission must name a file that
| still exists and still mentions the code, so an entry cannot rot into fiction.
|
*/

return [

    /*
     * code => where it is enforced.
     *
     * Keep entries specific enough that the named file is the reason the code
     * has an effect. "See RBAC docs" is not an enforcement point.
     */
    'references' => [
        'requests.view' => 'routes/api.php: GET api/requests (permission:requests.view) + app/Policies/LeaveRequestPolicy.php::view',
        'requests.create' => 'routes/api.php: POST api/requests (permission:requests.create)',
        'requests.approve' => 'app/Services/LeaveRequestService.php::decide for approved decisions (can requests approve) + components/leave/leave-sidebar.tsx',
        'requests.approve_zone' => 'capability only; consumed by components/leave/leave-sidebar.tsx, authority is workflow assignment ownership',
        'requests.view_own' => 'app/Policies/LeaveRequestPolicy.php::view (can requests view_own for the owner branch)',
        'requests.edit_own' => 'routes/api.php: PUT/PATCH api/requests/{id} (permission:requests.edit_own)',
        'requests.submit' => 'routes/api.php: POST api/requests/{id}/submit and POST api/requests/{id}/resubmit (permission:requests.submit)',
        'requests.withdraw' => 'routes/api.php: POST api/requests/{id}/withdraw (permission:requests.withdraw)',
        'requests.reject' => 'app/Services/LeaveRequestService.php::decide for rejected decisions (can requests reject)',
        'requests.return' => 'app/Services/LeaveRequestService.php::decide for returned decisions (can requests return)',
        'requests.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION',
        'policies.view' => 'routes/api.php: GET api/workflows + GET api/workflows/{id} (permission:policies.view)',
        'policies.create' => 'routes/api.php: POST api/policies (permission:policies.create)',
        'policies.edit_policy' => 'routes/api.php: POST and PATCH api/workflows (permission:policies.edit_policy) + app/Http/Controllers/Api/WorkflowController.php',
        'documents.view' => 'routes/api.php: GET api/requests/{id}/documents (permission:documents.view) + app/Policies/LeaveDocumentPolicy.php::view',
        'documents.create' => 'routes/api.php: POST api/requests/{id}/documents (permission:documents.create)',
        'documents.delete' => 'routes/api.php: DELETE api/requests/{id}/documents/{document} (permission:documents.delete) + app/Policies/LeaveDocumentPolicy.php::delete',
        'documents.view_medical_documents' => 'app/Policies/LeaveDocumentPolicy.php::view',
        // Note: the org READ endpoints (GET api/org/zones, /departments, /positions,
        // /tree) are deliberately NOT gated. Every authenticated employee needs zone and
        // department reference data to fill a leave form, and gating it on admin.view
        // broke the request form for ordinary staff. They expose no employee records,
        // no audit data and no configuration.
        'admin.view' => 'routes/api.php: GET api/roles, GET api/roles/permissions, GET api/audit, GET api/audit/verify (permission:admin.view)',
        'admin.create' => 'routes/api.php: POST role, employee, zone and department writes',
        'admin.update' => 'routes/api.php: PATCH role, employee, zone and department writes',
        'admin.delete' => 'routes/api.php: DELETE role, zone and department writes',
        'delegations.view' => 'routes/api.php: GET api/delegations (permission:delegations.view)',
        'delegations.create' => 'routes/api.php: POST api/delegations (permission:delegations.create)',
        'delegations.update' => 'routes/api.php: POST api/delegations/{id}/revoke (permission:delegations.update) + app/Http/Controllers/Api/DelegationController.php::revoke',
        'reports.view' => 'routes/api.php: GET api/reports/* (permission:reports.view)',
        'reports.view_gender_reports' => 'app/Http/Controllers/Api/ReportController.php::gender',
        'reports.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION',
        'staff.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION',
        'staff.view_own' => 'routes/api.php: GET api/org/employees and GET api/org/tree (permission:staff.view_own)',
        'approval_workflows.view' => 'routes/api.php: GET api/workflows and GET api/workflows/{id} (permission:approval_workflows.view)',
        'approval_workflows.create' => 'routes/api.php: POST api/workflows (permission:approval_workflows.create)',
        'approval_workflows.update' => 'routes/api.php: PUT/PATCH api/workflows/{id} (permission:approval_workflows.update)',
        'case_handovers.view' => 'routes/api.php: GET api/cases and GET api/cases/can-final-approve (permission:case_handovers.view)',
        'case_handovers.create' => 'routes/api.php: POST api/handovers/{request}/propose (permission:case_handovers.create) + app/Policies/LeaveRequestPolicy.php::proposeHandover',
        'case_handovers.accept' => 'routes/api.php: POST api/handovers/{handover}/accept (permission:case_handovers.accept)',
        'case_handovers.reject' => 'routes/api.php: POST api/handovers/{handover}/reject (permission:case_handovers.reject)',
        'case_handovers.complete' => 'routes/api.php: POST api/handovers/{handover}/complete (permission:case_handovers.complete) + app/Policies/CaseHandoverPolicy.php::complete',
        'leave_plans.create_own' => 'routes/api.php: POST api/leave-plans and POST api/leave-plans/{plan}/propose (permission:leave_plans.create_own)',
        'leave_plans.select_own' => 'routes/api.php: POST api/leave-plans/{plan}/select and POST api/leave-plans/{plan}/convert (permission:leave_plans.select_own)',
        'leave_plans.view_own' => 'app/Http/Controllers/Api/LeavePlanController.php::index (can leave_plans view_own)',
        'leave_plans.view_department' => 'app/Http/Controllers/Api/LeavePlanController.php::index and calendar (can leave_plans view_department)',
        'leave_plans.view_zone' => 'app/Http/Controllers/Api/LeavePlanController.php::index and calendar (can leave_plans view_zone)',
        'leave_plans.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_plans)',
        'leave_plans.confirm_department' => 'app/Http/Controllers/Api/LeavePlanController.php::confirm (can leave_plans confirm_department)',
        'leave_plans.confirm_zone' => 'app/Http/Controllers/Api/LeavePlanController.php::confirm (can leave_plans confirm_zone)',
        'leave_waivers.create_own' => 'routes/api.php: POST api/leave-waivers (permission:leave_waivers.create_own)',
        'leave_waivers.view_own' => 'app/Policies/LeaveWaiverPolicy.php::viewAny (can leave_waivers view_own)',
        'leave_waivers.review' => 'routes/api.php: POST api/leave-waivers/{waiver}/review (permission:leave_waivers.review) + app/Policies/LeaveWaiverPolicy.php::review',
        'leave_waivers.approve' => 'routes/api.php: POST api/leave-waivers/{waiver}/approve (permission:leave_waivers.approve) + app/Policies/LeaveWaiverPolicy.php::approve',
        'leave_waivers.reject' => 'routes/api.php: POST api/leave-waivers/{waiver}/reject (permission:leave_waivers.reject) + app/Policies/LeaveWaiverPolicy.php::reject',
        'leave_waivers.view_department' => 'app/Policies/LeaveWaiverPolicy.php::view (can leave_waivers view_department)',
        'leave_waivers.view_zone' => 'app/Policies/LeaveWaiverPolicy.php::view (can leave_waivers view_zone)',
        'leave_waivers.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_waivers) + app/Policies/LeaveWaiverPolicy.php::view',
        'leave_returns.report_own' => 'routes/api.php: POST api/leave-requests/{id}/return and POST api/leave-returns/{id}/explanation (permission:leave_returns.report_own) + app/Policies/LeaveReturnPolicy.php::report',
        'leave_returns.view_own' => 'app/Policies/LeaveReturnPolicy.php::viewAny (can leave_returns view_own)',
        'leave_returns.view_department' => 'app/Policies/LeaveReturnPolicy.php::view (can leave_returns view_department)',
        'leave_returns.view_zone' => 'app/Policies/LeaveReturnPolicy.php::view (can leave_returns view_zone)',
        'leave_returns.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_returns) + app/Policies/LeaveReturnPolicy.php::view',
        'leave_returns.review' => 'routes/api.php: POST api/leave-returns/{id}/review (permission:leave_returns.review) + app/Policies/LeaveReturnPolicy.php::review',
        'leave_returns.excuse' => 'app/Services/LateReturnService.php::review (can leave_returns excuse)',
        'leave_returns.apply_deduction' => 'app/Services/LateReturnService.php::review (can leave_returns apply_deduction)',
        'leave_returns.close' => 'routes/api.php: POST api/leave-returns/{id}/close (permission:leave_returns.close) + app/Policies/LeaveReturnPolicy.php::close',
        'leave_payments.view_own' => 'app/Policies/LeavePaymentCasePolicy.php::viewAny (can leave_payments view_own)',
        'leave_payments.view_department' => 'app/Policies/LeavePaymentCasePolicy.php::view (can leave_payments view_department)',
        'leave_payments.view_zone' => 'app/Policies/LeavePaymentCasePolicy.php::view (can leave_payments view_zone)',
        'leave_payments.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_payments) + app/Policies/LeavePaymentCasePolicy.php::view',
        'leave_payments.create' => 'routes/api.php: POST api/leave-payments and POST api/leave-payments/{id}/evaluate, /submit (permission:leave_payments.create) + app/Http/Controllers/Api/LeavePaymentController.php::store',
        'leave_payments.review' => 'routes/api.php: POST api/leave-payments/{id}/send-to-accounting (permission:leave_payments.review) + app/Policies/LeavePaymentCasePolicy.php::sendToAccounting',
        'leave_payments.approve' => 'routes/api.php: POST api/leave-payments/{id}/approve (permission:leave_payments.approve) + app/Policies/LeavePaymentCasePolicy.php::approve',
        'leave_payments.reject' => 'routes/api.php: POST api/leave-payments/{id}/reject (permission:leave_payments.reject) + app/Policies/LeavePaymentCasePolicy.php::reject',
        'leave_payments.receive' => 'routes/api.php: POST api/leave-payments/{id}/receive (permission:leave_payments.receive) + app/Policies/LeavePaymentCasePolicy.php::receive',
        'leave_payments.process' => 'routes/api.php: POST api/leave-payments/{id}/paid and /reject-payment (permission:leave_payments.process) + app/Policies/LeavePaymentCasePolicy.php::process',
        'leave_payments.cancel' => 'routes/api.php: POST api/leave-payments/{id}/cancel (permission:leave_payments.cancel) + app/Policies/LeavePaymentCasePolicy.php::cancel',
        'leave_documents.generate' => 'routes/api.php: POST api/leave-requests/{id}/letters/generate (permission:leave_documents.generate) + app/Http/Controllers/Api/OfficialLetterController.php::generateApproval',
        'leave_documents.view_own' => 'app/Policies/OfficialLeaveLetterPolicy.php::viewAny (can leave_documents view_own)',
        'leave_documents.download_own' => 'app/Policies/OfficialLeaveLetterPolicy.php::downloadOwn (can leave_documents download_own)',
        'leave_documents.download_hr' => 'app/Policies/OfficialLeaveLetterPolicy.php::downloadHr (can leave_documents download_hr) + app/Http/Controllers/Api/OfficialLetterController.php::temporaryUrl',
        'leave_documents.revoke' => 'routes/api.php: POST api/leave-requests/{id}/letters/{letter}/revoke (permission:leave_documents.revoke) + app/Policies/OfficialLeaveLetterPolicy.php::revoke',
        'notifications.view_own' => 'routes/api.php: GET api/notifications, GET api/notifications/unread-count, POST api/notifications/{id}/read (permission:notifications.view_own)',
        'reports.view_own' => 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (scope own)',
        'reports.view_department' => 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (scope department)',
        'reports.view_zone' => 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (scope zone)',
        'reports.view_organisation' => 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (cross-zone)',
        'reports.export' => 'routes/api.php: POST api/reports/exports (permission:reports.export) + app/Http/Controllers/Api/ReportController.php::createExport',
        'reports.view_audit' => 'routes/api.php: GET api/reports/audit (permission:reports.view_audit) + app/Http/Controllers/Api/ReportController.php::auditSearch',
        'reports.view_financial' => 'app/Services/ReportAuthorizationService.php::canViewReports (financial gate) + app/Services/ReportQueryService.php',
        'reports.view_sensitive' => 'app/Services/ReportAuthorizationService.php::canViewReports (sensitive gate) + app/Services/ReportQueryService.php',
        'reports.view_cross_zone' => 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module reports)',
        'reports.view_gender_reports' => 'app/Http/Controllers/Api/ReportController.php::gender (permission:reports.view_gender_reports)',
        'reports.export' => 'routes/api.php: POST api/reports/exports (permission:reports.export) + app/Http/Controllers/Api/ReportController.php::createExport',
        'report_exports.download' => 'routes/api.php: GET api/reports/exports/{id}/download (permission:report_exports.download) + app/Http/Controllers/Api/ReportController.php::downloadExport',
        'report_exports.cancel' => 'routes/api.php: POST api/reports/exports/{id}/cancel (permission:report_exports.cancel) + app/Http/Controllers/Api/ReportController.php::cancelExport',
        'audit_logs.view' => 'routes/api.php: GET api/reports/audit (permission:audit_logs.view) + app/Http/Controllers/Api/ReportController.php::auditSearch + app/Services/AuditReportService.php',
        'notifications.manage_preferences' => 'routes/api.php: GET and PATCH api/notifications/preferences (permission:notifications.manage_preferences) + app/Http/Controllers/Api/NotificationPreferenceController.php',
        'notifications.view_sensitive' => 'app/Services/LeaveNotificationService.php::message (can notifications view_sensitive gates leave type/reason detail)',
    ],

    /*
     * Codes that gate a frontend surface but no backend check.
     *
     * leave-sidebar.tsx requires requests.approve_zone to surface the
     * Approvals nav item for zone-oversight holders. The server does not
     * consult it (cross-zone decision reach is governed by assignment
     * ownership plus scope), so granting it widens the UI but never the API
     * surface. requests.approve used to live here; since the governance
     * normalization it is enforced by RequestController::decide alongside
     * assignment ownership (capability AND ownership).
     *
     * This is a deliberate exception, not a backlog item. Adding a code here
     * grants a switch that promises something the API does not deliver, so it
     * needs to be argued for, not slipped in.
     */
    'frontend_only' => [
        'requests.approve_zone',
    ],

];
