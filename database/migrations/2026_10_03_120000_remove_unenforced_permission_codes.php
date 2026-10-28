<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes permission codes that are rendered as switches in the RBAC matrix but
 * are read by nothing. Flipping such a switch changed no request outcome while
 * looking like it had, which is the worst failure mode for an access-control
 * screen: an admin unticks "requests.reject" and concludes HR can no longer
 * reject, when the code was never consulted in the first place.
 *
 * Three groups are removed.
 *
 * 1. Decision and CRUD codes with no endpoint (8)
 *      requests.reject / .return / .update / .delete / .export,
 *      reports.export, policies.create / .update / .delete, documents.delete
 *    LeaveRequestController::decide() derives decision rights from workflow
 *    assignment ownership (LeaveRequestPolicy::approve); requests are never
 *    updated or deleted through the API; there are no export routes; POST
 *    /api/policies is gated by policies.edit_policy.
 *
 * 2. Navigation codes added by 2026_09_14_110000 purely so every sidebar module
 *    would appear in the matrix (24)
 *      dashboard.*, myleave.*, approvals.*, calendar.*, workflows.*, cases.*,
 *      notifications.*, audit.*
 *    The frontend never gates on these: leave-sidebar.tsx requires requests.view,
 *    policies.view, documents.view, delegations.view, reports.view, admin.view
 *    and requests.approve / requests.approve_zone. These codes had no reader at
 *    all, and no role held them.
 *
 * 3. staff.view (1)
 *    Staff-directory scoping is driven entirely by the assignment's zone and
 *    department plus staff.view_cross_zone (EmployeePolicy, AccessScopeService).
 *    staff.view was mentioned only in a docblock.
 *
 * Deliberately KEPT, because something really does read them:
 *
 *   requests.approve, requests.approve_zone
 *      Frontend capability signal for the Approvals surface (leave-sidebar.tsx,
 *      approvals.tsx). Not backend gates, but not decorative either.
 *   admin.*, documents.create, policies.edit_policy, policies.view,
 *   delegations.*, reports.view, reports.view_gender_reports, requests.view,
 *   requests.create
 *      Route middleware.
 *   documents.view, documents.view_medical_documents, *.view_cross_zone
 *      LeaveDocumentPolicy, PermissionService::SELF_SERVICE, and
 *      AccessScopeService::canViewAllZones().
 *
 * role_permissions.permission_id is ON DELETE CASCADE, so grants vanish with the
 * code. Idempotent.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private array $codes = [
        // 1. decision + CRUD codes with no endpoint
        'requests.reject',
        'requests.return',
        'requests.update',
        'requests.delete',
        'requests.export',
        'reports.export',
        'policies.create',
        'policies.update',
        'policies.delete',
        'documents.delete',

        // 2. navigation codes from 2026_09_14_110000
        'dashboard.view',
        'myleave.view',
        'myleave.create',
        'myleave.update',
        'myleave.delete',
        'approvals.view',
        'approvals.approve',
        'approvals.return',
        'approvals.reject',
        'approvals.export',
        'calendar.view',
        'calendar.export',
        'workflows.view',
        'workflows.create',
        'workflows.update',
        'workflows.delete',
        'cases.view',
        'cases.create',
        'cases.update',
        'cases.delete',
        'cases.export',
        'notifications.view',
        'audit.view',
        'audit.export',

        // 3. staff directory read
        'staff.view',
    ];

    public function up(): void
    {
        DB::table('permissions')->whereIn('code', $this->codes)->delete();
    }

    public function down(): void
    {
        foreach ($this->codes as $code) {
            if (DB::table('permissions')->where('code', $code)->exists()) {
                continue;
            }

            [$module, $action] = explode('.', $code, 2);

            DB::table('permissions')->insert([
                'code' => $code,
                'module' => $module,
                'action' => $action,
                'label' => $action,
                'hint' => "{$module} {$action}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
