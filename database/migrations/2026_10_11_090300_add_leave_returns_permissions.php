<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 catalog: the return-to-work permission codes and their module.
 *
 * Idempotent, so it is safe on a database already seeded by DatabaseSeeder
 * (which inserts the same codes). The `modules` row is required by
 * PermissionCatalogIntegrityTest: a permission whose module has no row would
 * make the module screen and the RBAC matrix disagree.
 *
 * Role grants are attached here only when the role already exists (migrations
 * run before the seeder on a fresh database). DatabaseSeeder grants the same
 * rows for fresh installs, so neither ordering loses a grant.
 *
 * `view_cross_zone` is the one addition beyond the specification's list: the
 * documented AccessScopeService invariant is that organisation-wide reach is
 * granted ONLY by an explicit `<module>.view_cross_zone` code. Without it a
 * central role such as HR could never see return cases outside its own
 * departmental scope, and Phase 2's leave_plans/leave_waivers modules carry the
 * same code for exactly this reason.
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'leave_returns' => [
            'label' => 'Return to Work',
            'description' => 'Return-to-work reporting and late-return review and deductions.',
        ],
    ];

    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'leave_returns.report_own' => ['module' => 'leave_returns', 'hint' => 'Report your own return to work after approved leave.'],
        'leave_returns.view_own' => ['module' => 'leave_returns', 'hint' => 'View your own return-to-work cases.'],
        'leave_returns.view_department' => ['module' => 'leave_returns', 'hint' => 'View return-to-work cases for staff in departments you head.'],
        'leave_returns.view_zone' => ['module' => 'leave_returns', 'hint' => 'View return-to-work cases for staff in zones you head.'],
        'leave_returns.view_cross_zone' => ['module' => 'leave_returns', 'hint' => 'View return-to-work cases across ALL departments and zones. Central offices only; regional roles stay zone-scoped.'],
        'leave_returns.review' => ['module' => 'leave_returns', 'hint' => 'Review and decide a reported late return.'],
        'leave_returns.excuse' => ['module' => 'leave_returns', 'hint' => 'Excuse late-return days as authorised absence.'],
        'leave_returns.apply_deduction' => ['module' => 'leave_returns', 'hint' => 'Apply a balance deduction for unauthorised late-return days.'],
        'leave_returns.close' => ['module' => 'leave_returns', 'hint' => 'Close a return-to-work case once it needs no further action.'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'SELF_SERVICE' => ['leave_returns.report_own', 'leave_returns.view_own'],
        'DEPT_HEAD' => ['leave_returns.view_department', 'leave_returns.review', 'leave_returns.excuse', 'leave_returns.apply_deduction', 'leave_returns.close'],
        'ZONAL_HEAD' => ['leave_returns.view_zone', 'leave_returns.review', 'leave_returns.excuse', 'leave_returns.apply_deduction', 'leave_returns.close'],
        'HR' => ['leave_returns.report_own', 'leave_returns.view_own', 'leave_returns.view_department', 'leave_returns.view_zone', 'leave_returns.view_cross_zone', 'leave_returns.review', 'leave_returns.excuse', 'leave_returns.apply_deduction', 'leave_returns.close'],
        'FINAL_AUTHORITY' => ['leave_returns.report_own', 'leave_returns.view_own', 'leave_returns.view_department', 'leave_returns.view_zone', 'leave_returns.view_cross_zone', 'leave_returns.review', 'leave_returns.excuse', 'leave_returns.apply_deduction', 'leave_returns.close'],
        'BREAK_GLASS' => ['leave_returns.report_own', 'leave_returns.view_own', 'leave_returns.view_department', 'leave_returns.view_zone', 'leave_returns.view_cross_zone', 'leave_returns.review', 'leave_returns.excuse', 'leave_returns.apply_deduction', 'leave_returns.close'],
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

            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => $code,
                    'module' => $meta['module'],
                    'action' => $action,
                    'label' => $action,
                    'hint' => $meta['hint'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionIds[$code] = $permissionId;
        }

        foreach ($this->grants as $roleId => $codes) {
            if (! DB::table('roles')->where('id', $roleId)->exists()) {
                continue;
            }

            foreach ($codes as $code) {
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
