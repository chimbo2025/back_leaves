<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 catalog: the leave-planning and leave-waiver permission codes, plus
 * the two modules they belong to.
 *
 * Idempotent, so it is safe on a database already seeded by DatabaseSeeder
 * (which inserts the same codes). Module rows are required by
 * PermissionCatalogIntegrityTest: a permission whose module has no `modules`
 * row would make the module screen and the RBAC matrix disagree.
 *
 * Role grants are attached here only when the role already exists (migrations
 * run before the seeder on a fresh database). DatabaseSeeder grants the same
 * rows for fresh installs, so neither ordering loses a grant.
 */
return new class extends Migration
{
    /** @var array<string,array{label:string,description:string}> */
    private array $modules = [
        'leave_plans' => [
            'label' => 'Leave Planning',
            'description' => 'Almanac leave planning: date proposals, options and confirmation.',
        ],
        'leave_waivers' => [
            'label' => 'Leave Waivers',
            'description' => 'Leave waiver, deferral and rescheduling requests.',
        ],
    ];

    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'leave_plans.view_own' => ['module' => 'leave_plans', 'hint' => 'View your own leave plans.'],
        'leave_plans.create_own' => ['module' => 'leave_plans', 'hint' => 'Create and generate options for your own leave plans.'],
        'leave_plans.select_own' => ['module' => 'leave_plans', 'hint' => 'Select an option and convert your own plan into a leave request.'],
        'leave_plans.view_department' => ['module' => 'leave_plans', 'hint' => 'View leave plans for staff in departments you head.'],
        'leave_plans.view_zone' => ['module' => 'leave_plans', 'hint' => 'View leave plans for staff in zones you head.'],
        'leave_plans.view_cross_zone' => ['module' => 'leave_plans', 'hint' => 'View leave plans across ALL departments and zones. Central offices only; regional roles stay zone-scoped.'],
        'leave_plans.confirm_department' => ['module' => 'leave_plans', 'hint' => 'Confirm leave plans for staff in departments you head.'],
        'leave_plans.confirm_zone' => ['module' => 'leave_plans', 'hint' => 'Confirm leave plans for staff in zones you head.'],
        'leave_waivers.create_own' => ['module' => 'leave_waivers', 'hint' => 'Create and submit your own waiver or deferral request.'],
        'leave_waivers.review' => ['module' => 'leave_waivers', 'hint' => 'Review waiver requests routed to you.'],
        'leave_waivers.approve' => ['module' => 'leave_waivers', 'hint' => 'Approve or reject waiver requests.'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'SELF_SERVICE' => ['leave_plans.view_own', 'leave_plans.create_own', 'leave_plans.select_own', 'leave_waivers.create_own'],
        'DEPT_HEAD' => ['leave_plans.view_department', 'leave_plans.confirm_department'],
        'ZONAL_HEAD' => ['leave_plans.view_zone', 'leave_plans.confirm_zone'],
        'HR' => ['leave_plans.view_own', 'leave_plans.create_own', 'leave_plans.select_own', 'leave_plans.view_department', 'leave_plans.view_zone', 'leave_plans.view_cross_zone', 'leave_plans.confirm_department', 'leave_plans.confirm_zone', 'leave_waivers.create_own', 'leave_waivers.review', 'leave_waivers.approve'],
        'FINAL_AUTHORITY' => ['leave_plans.view_own', 'leave_plans.create_own', 'leave_plans.select_own', 'leave_plans.view_department', 'leave_plans.view_zone', 'leave_plans.view_cross_zone', 'leave_plans.confirm_department', 'leave_plans.confirm_zone', 'leave_waivers.create_own', 'leave_waivers.review', 'leave_waivers.approve'],
        'BREAK_GLASS' => ['leave_plans.view_own', 'leave_plans.create_own', 'leave_plans.select_own', 'leave_plans.view_department', 'leave_plans.view_zone', 'leave_plans.view_cross_zone', 'leave_plans.confirm_department', 'leave_plans.confirm_zone', 'leave_waivers.create_own', 'leave_waivers.review', 'leave_waivers.approve'],
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
