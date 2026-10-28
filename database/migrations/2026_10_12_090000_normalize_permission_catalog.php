<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Governance normalization: dedicated workflow + handover modules and
 * explicit lifecycle/scope codes.
 *
 * Design convention adopted here (and documented per code in
 * config/permission_enforcement.php):
 *
 * - Every feature owns `view_own / view_department / view_zone /
 *   view_cross_zone` scope codes plus its action codes. Codes are always
 *   fully qualified (`module.action`); the bare suffixes are never matched
 *   on their own, so identical suffixes across modules cannot collide.
 * - A code is `implemented` only when an enforcement point exists in the
 *   same change (route middleware or policy/service `can()` call with a
 *   quoted 'module.action' literal). Codes without an endpoint yet land as
 *   `planned / active=false`: visible in the matrix, ungrantable, wired later.
 * - Existing codes are never renamed or removed here. In particular
 *   `reports.view_gender_reports` keeps its shipped name, and there is no
 *   `updated` action anywhere to normalize (verified absent).
 *
 * New modules:
 * - approval_workflows: workflow-definition configuration, previously gated
 *   by policies.view / policies.edit_policy.
 * - case_handovers: dockets and handovers, previously authorized policy-inline
 *   with no route code (invisible in the matrix).
 *
 * New lifecycle/scope codes:
 * - requests.view_own/edit_own/submit/withdraw/reject/return (implemented);
 *   requests.view_department/view_zone/cancel (planned — no distinct
 *   endpoint; scope continues to come from assignment grants).
 * - policies.update/archive/publish (planned — policy authoring is
 *   versioned inserts; no update/archive/publish endpoint exists yet).
 * - staff.view_own (implemented — staff directory/tree);
 *   staff.view_department/view_zone (planned).
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'approval_workflows' => [
            'label' => 'Approval Workflows',
            'description' => 'Configuration of approval chains: who approves what, in which order, with which SLA.',
        ],
        'case_handovers' => [
            'label' => 'Case Handovers',
            'description' => 'Dockets attached to leave requests and ownership handovers between holders.',
        ],
    ];

    /**
     * @var array<string,array{module:string,hint:string,lifecycle:string}>
     *                                                                      lifecycle is 'implemented' (wired in this change) or 'planned'.
     */
    private array $catalog = [
        // Approval workflows: reads/writes wired in routes/api.php; the
        // remaining three have no endpoint yet.
        'approval_workflows.view' => ['module' => 'approval_workflows', 'hint' => 'View approval workflow definitions.', 'lifecycle' => 'implemented'],
        'approval_workflows.create' => ['module' => 'approval_workflows', 'hint' => 'Create approval workflow definitions.', 'lifecycle' => 'implemented'],
        'approval_workflows.update' => ['module' => 'approval_workflows', 'hint' => 'Edit approval workflow definitions.', 'lifecycle' => 'implemented'],
        'approval_workflows.delete' => ['module' => 'approval_workflows', 'hint' => 'Delete approval workflow definitions (reserved).', 'lifecycle' => 'planned'],
        'approval_workflows.activate' => ['module' => 'approval_workflows', 'hint' => 'Activate a workflow version (reserved).', 'lifecycle' => 'planned'],
        'approval_workflows.archive' => ['module' => 'approval_workflows', 'hint' => 'Archive a workflow version (reserved).', 'lifecycle' => 'planned'],
        'approval_workflows.test' => ['module' => 'approval_workflows', 'hint' => 'Dry-run a workflow definition (reserved).', 'lifecycle' => 'planned'],
        // Case handovers: every route carries its code; cancel has no endpoint.
        'case_handovers.view' => ['module' => 'case_handovers', 'hint' => 'View dockets and handovers you may see.', 'lifecycle' => 'implemented'],
        'case_handovers.create' => ['module' => 'case_handovers', 'hint' => 'Propose a handover of a docket.', 'lifecycle' => 'implemented'],
        'case_handovers.accept' => ['module' => 'case_handovers', 'hint' => 'Accept a handover proposed to you.', 'lifecycle' => 'implemented'],
        'case_handovers.reject' => ['module' => 'case_handovers', 'hint' => 'Reject a handover proposed to you.', 'lifecycle' => 'implemented'],
        'case_handovers.complete' => ['module' => 'case_handovers', 'hint' => 'Sign off handover completion.', 'lifecycle' => 'implemented'],
        'case_handovers.cancel' => ['module' => 'case_handovers', 'hint' => 'Cancel a proposed handover (reserved).', 'lifecycle' => 'planned'],
        // Request lifecycle: owner-scoped actions wired on their routes;
        // decision codes wired inside decide() per decision.
        'requests.view_own' => ['module' => 'requests', 'hint' => 'View your own leave requests.', 'lifecycle' => 'implemented'],
        'requests.edit_own' => ['module' => 'requests', 'hint' => 'Edit your own draft requests.', 'lifecycle' => 'implemented'],
        'requests.submit' => ['module' => 'requests', 'hint' => 'Submit and resubmit your own requests.', 'lifecycle' => 'implemented'],
        'requests.withdraw' => ['module' => 'requests', 'hint' => 'Withdraw your own requests.', 'lifecycle' => 'implemented'],
        'requests.reject' => ['module' => 'requests', 'hint' => 'Reject a step assigned to you.', 'lifecycle' => 'implemented'],
        'requests.return' => ['module' => 'requests', 'hint' => 'Return a step assigned to you for correction.', 'lifecycle' => 'implemented'],
        'requests.view_department' => ['module' => 'requests', 'hint' => 'Department-scoped request visibility (reserved).', 'lifecycle' => 'planned'],
        'requests.view_zone' => ['module' => 'requests', 'hint' => 'Zone-scoped request visibility (reserved).', 'lifecycle' => 'planned'],
        'requests.cancel' => ['module' => 'requests', 'hint' => 'Cancel a request (reserved).', 'lifecycle' => 'planned'],
        // Policy lifecycle: authoring stays versioned inserts; no endpoints yet.
        'policies.update' => ['module' => 'policies', 'hint' => 'Edit a policy in place (reserved; authoring is versioned).', 'lifecycle' => 'planned'],
        'policies.archive' => ['module' => 'policies', 'hint' => 'Archive a policy version (reserved).', 'lifecycle' => 'planned'],
        'policies.publish' => ['module' => 'policies', 'hint' => 'Publish a policy version (reserved).', 'lifecycle' => 'planned'],
        // Staff directory: own-record visibility wired on directory endpoints.
        'staff.view_own' => ['module' => 'staff', 'hint' => 'View the staff directory.', 'lifecycle' => 'implemented'],
        'staff.view_department' => ['module' => 'staff', 'hint' => 'Department-scoped staff visibility (reserved).', 'lifecycle' => 'planned'],
        'staff.view_zone' => ['module' => 'staff', 'hint' => 'Zone-scoped staff visibility (reserved).', 'lifecycle' => 'planned'],
    ];

    /** @var array<string,array<int,string>> role => codes (implemented only) */
    private array $grants = [
        // Everyone keeps what they could already do: own requests, own
        // directory entry, own dockets. New codes must not silently remove.
        'SELF_SERVICE' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw',
            'staff.view_own',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
            'approval_workflows.view',
        ],
        // Approvers keep chain configuration visibility plus decision codes.
        'SUPERVISOR' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw', 'requests.reject', 'requests.return',
            'staff.view_own',
            'approval_workflows.view',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
        ],
        'DEPT_HEAD' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw', 'requests.reject', 'requests.return',
            'staff.view_own',
            'approval_workflows.view',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
        ],
        'ZONAL_HEAD' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw', 'requests.reject', 'requests.return',
            'staff.view_own',
            'approval_workflows.view',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
        ],
        // HR configures workflows and sees every handover.
        'HR' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw', 'requests.reject', 'requests.return',
            'staff.view_own',
            'approval_workflows.view', 'approval_workflows.create', 'approval_workflows.update',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
        ],
        // The authority roles hold the WHOLE catalog — including planned
        // codes. Planned codes stay unenforceable (grantablePermissions
        // excludes them, so codesFor() never contains them) and ungrantable
        // via the API (syncPermissions 422s), but the "every code is held by
        // at least one active role" invariant and the authority-holds-all
        // documentation both assume full coverage. Assignment paths still
        // cap BREAK_GLASS at 30 days.
        'FINAL_AUTHORITY' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw', 'requests.reject', 'requests.return',
            'requests.view_department', 'requests.view_zone', 'requests.cancel',
            'policies.update', 'policies.archive', 'policies.publish',
            'staff.view_own', 'staff.view_department', 'staff.view_zone',
            'approval_workflows.view', 'approval_workflows.create', 'approval_workflows.update',
            'approval_workflows.delete', 'approval_workflows.activate', 'approval_workflows.archive', 'approval_workflows.test',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
            'case_handovers.cancel',
        ],
        'BREAK_GLASS' => [
            'requests.view_own', 'requests.edit_own', 'requests.submit', 'requests.withdraw', 'requests.reject', 'requests.return',
            'requests.view_department', 'requests.view_zone', 'requests.cancel',
            'policies.update', 'policies.archive', 'policies.publish',
            'staff.view_own', 'staff.view_department', 'staff.view_zone',
            'approval_workflows.view', 'approval_workflows.create', 'approval_workflows.update',
            'approval_workflows.delete', 'approval_workflows.activate', 'approval_workflows.archive', 'approval_workflows.test',
            'case_handovers.view', 'case_handovers.create', 'case_handovers.accept', 'case_handovers.reject', 'case_handovers.complete',
            'case_handovers.cancel',
        ],
        // ACCOUNTING keeps settlement-only: no workflow, handover, request or
        // staff codes. Separation of duties is unchanged.
    ];

    public function up(): void
    {
        foreach ($this->modules as $id => $meta) {
            DB::table('modules')->updateOrInsert(
                ['id' => $id],
                ['label' => $meta['label'], 'description' => $meta['description'], 'is_active' => true]
            );
        }

        $permissionIds = [];

        foreach ($this->catalog as $code => $meta) {
            $action = substr($code, strlen($meta['module']) + 1);

            $row = DB::table('permissions')->where('code', $code)->first();

            if (! $row) {
                $id = DB::table('permissions')->insertGetId([
                    'code' => $code,
                    'module' => $meta['module'],
                    'action' => $action,
                    'label' => $action,
                    'hint' => $meta['hint'],
                    'status' => $meta['lifecycle'],
                    'active' => $meta['lifecycle'] === 'implemented',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $id = $row->id;
                // Converge hand-written rows to the catalogued lifecycle.
                DB::table('permissions')->where('id', $id)->update([
                    'module' => $meta['module'],
                    'hint' => $meta['hint'],
                    'status' => $meta['lifecycle'],
                    'active' => $meta['lifecycle'] === 'implemented',
                    'updated_at' => now(),
                ]);
            }

            $permissionIds[$code] = $id;
        }

        foreach ($this->grants as $roleId => $codes) {
            if (! DB::table('roles')->where('id', $roleId)->exists()) {
                continue;
            }

            foreach ($codes as $code) {
                // Planned codes are never granted, even if listed by mistake.
                if (($this->catalog[$code]['lifecycle'] ?? null) !== 'implemented') {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionIds[$code])
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionIds[$code],
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys($this->catalog))->pluck('id')->all();

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('modules')->whereIn('id', array_keys($this->modules))->delete();
    }
};
