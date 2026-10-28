<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 balance decision: the leave-waiver permission codes needed for
 * scope-aware direct review.
 *
 * The original Phase 2 catalog carried only create_own / review / approve,
 * which made every reviewer implicitly central. This adds an explicit
 * own/department/zone/cross-zone read model plus an explicit reject code, so a
 * department or zonal reviewer can only act inside their own scope and the
 * organisation-wide reach still requires an explicit view_cross_zone code
 * (AccessScopeService's documented invariant).
 *
 * Idempotent, so it is safe against a database already seeded by DatabaseSeeder.
 */
return new class extends Migration
{
    /** @var array<string,array{module:string,hint:string}> */
    private array $catalog = [
        'leave_waivers.view_own' => ['module' => 'leave_waivers', 'hint' => 'View your own waiver and deferral requests.'],
        'leave_waivers.reject' => ['module' => 'leave_waivers', 'hint' => 'Reject waiver or deferral requests.'],
        'leave_waivers.view_department' => ['module' => 'leave_waivers', 'hint' => 'View waiver requests for staff in departments you head.'],
        'leave_waivers.view_zone' => ['module' => 'leave_waivers', 'hint' => 'View waiver requests for staff in zones you head.'],
        'leave_waivers.view_cross_zone' => ['module' => 'leave_waivers', 'hint' => 'View waiver requests across ALL departments and zones. Central offices only; regional roles stay zone-scoped.'],
    ];

    /** @var array<string,array<int,string>> role => codes */
    private array $grants = [
        'SELF_SERVICE' => ['leave_waivers.view_own'],
        'DEPT_HEAD' => ['leave_waivers.view_department', 'leave_waivers.review', 'leave_waivers.reject'],
        'ZONAL_HEAD' => ['leave_waivers.view_zone', 'leave_waivers.review', 'leave_waivers.reject'],
        'HR' => ['leave_waivers.view_own', 'leave_waivers.review', 'leave_waivers.reject', 'leave_waivers.view_department', 'leave_waivers.view_zone', 'leave_waivers.view_cross_zone'],
        'FINAL_AUTHORITY' => ['leave_waivers.view_own', 'leave_waivers.review', 'leave_waivers.reject', 'leave_waivers.view_department', 'leave_waivers.view_zone', 'leave_waivers.view_cross_zone'],
        'BREAK_GLASS' => ['leave_waivers.view_own', 'leave_waivers.review', 'leave_waivers.reject', 'leave_waivers.view_department', 'leave_waivers.view_zone', 'leave_waivers.view_cross_zone'],
    ];

    public function up(): void
    {
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
                // review/approve already exist from the Phase 2 planning
                // migration, so resolve them from the catalog rather than the
                // (new-codes-only) map built above.
                $permissionId = $permissionIds[$code]
                    ?? DB::table('permissions')->where('code', $code)->value('id');

                if (! $permissionId) {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
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
    }
};
