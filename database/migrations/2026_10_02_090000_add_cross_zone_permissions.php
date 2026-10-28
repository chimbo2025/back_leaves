<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the explicit cross-zone permission codes that gate organisation-wide
 * staff/leave visibility ("Regional offices only access staff and requests
 * within their own zone unless central permission is granted").
 *
 * Idempotent: safe to run against databases already seeded by DatabaseSeeder,
 * which inserts the same codes. Grants are limited to the two central roles —
 * HR (central HR office) and FINAL_AUTHORITY (DPP). ZONAL_HEAD and DEPT_HEAD
 * are deliberately NOT granted; they resolve to their scope_zone_id /
 * department_id grants instead.
 */
return new class extends Migration
{
    /** @var array<string,array{module:string,label:string,hint:string}> */
    private array $catalog = [
        'requests.view_cross_zone' => [
            'module' => 'requests',
            'label' => 'view_cross_zone',
            'hint' => 'View leave requests across ALL zones/departments. Central offices only; regional roles stay zone-scoped.',
        ],
        'staff.view_cross_zone' => [
            'module' => 'staff',
            'label' => 'view_cross_zone',
            'hint' => 'View the staff directory across ALL zones/departments. Central offices only.',
        ],
        'reports.view_cross_zone' => [
            'module' => 'reports',
            'label' => 'view_cross_zone',
            'hint' => 'Run organisation-wide leave reports. Zone-scoped by default; central offices only for nation-wide figures.',
        ],
    ];

    /** @var array<int,string> */
    private array $centralRoles = ['HR', 'FINAL_AUTHORITY'];

    public function up(): void
    {
        foreach ($this->catalog as $code => $meta) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => $code,
                    'module' => $meta['module'],
                    'action' => 'view_cross_zone',
                    'label' => $meta['label'],
                    'hint' => $meta['hint'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($this->centralRoles as $roleId) {
                // Migrations run BEFORE the seeder on a fresh database, so the
                // roles may not exist yet. Grants are only attached when the
                // role row is present; DatabaseSeeder grants the same rows for
                // fresh installs, so no grant is lost either way.
                if (! DB::table('roles')->where('id', $roleId)->exists()) {
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
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys($this->catalog))
            ->pluck('id')
            ->all();

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
