<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend the permission catalog so every navigation module is fetchable in
     * the permission matrix (/roles/permissions): dashboard, myleave, approvals,
     * calendar, workflows, cases, notifications and audit on top of the existing
     * requests/policies/documents/delegations/reports/admin rows. Codes are the
     * same module.action phrasing the matrix UI toggles via PATCH /roles/{id}.
     */
    public function up(): void
    {
        $catalog = [
            'dashboard' => ['view'],
            'myleave' => ['view', 'create', 'update', 'delete'],
            'approvals' => ['view', 'approve', 'return', 'reject', 'export'],
            'calendar' => ['view', 'export'],
            'workflows' => ['view', 'create', 'update', 'delete'],
            'cases' => ['view', 'create', 'update', 'delete', 'export'],
            'notifications' => ['view'],
            'audit' => ['view', 'export'],
        ];

        foreach ($catalog as $module => $actions) {
            foreach ($actions as $action) {
                $code = "{$module}.{$action}";
                if (DB::table('permissions')->where('code', $code)->exists()) {
                    continue;
                }
                DB::table('permissions')->insert([
                    'code' => $code,
                    'module' => $module,
                    'action' => $action,
                    'label' => $code,
                    'hint' => "{$module} {$action}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('module', [
            'dashboard',
            'myleave',
            'approvals',
            'calendar',
            'workflows',
            'cases',
            'notifications',
            'audit',
        ])->delete();
    }
};
