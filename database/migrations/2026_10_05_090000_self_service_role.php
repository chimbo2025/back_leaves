<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns the self-service floor into a real role.
 *
 * PermissionService used to merge four codes into every user's set
 * unconditionally:
 *
 *     array_merge($codes, self::SELF_SERVICE)
 *
 * Those four could therefore never be revoked. Deleting `policies.view` from a
 * role in the matrix and reloading left the holder with `policies.view` — the
 * switch looked broken because the merge silently re-added it. Authority was
 * then decided by code, not by the permission matrix, which is the opposite of
 * what the matrix claims to express.
 *
 * The floor itself is legitimate: without it nobody can file a leave request or
 * read their own records. The defect was that it was invisible and
 * unrevocable. So it becomes an ordinary role, assigned like any other, and the
 * merge is removed. From here on, what a person can do is exactly what their
 * roles say.
 */
return new class extends Migration
{
    /** The four codes the removed merge used to inject. */
    private const CODES = ['requests.view', 'requests.create', 'policies.view', 'documents.view'];

    public function up(): void
    {
        // Fresh install: migrations run before the seeder, so no roles and no
        // employees exist yet. DatabaseSeeder creates the SELF_SERVICE role, its
        // codes and the per-employee assignment itself. Same guard as
        // 2026_10_04_090000, and inserting here would collide with it.
        if (DB::table('roles')->count() === 0) {
            return;
        }

        $now = now();

        DB::table('roles')->updateOrInsert(
            ['id' => 'SELF_SERVICE'],
            [
                'name' => 'Self-Service',
                'description' => 'Baseline for every active employee: read and file their own leave, read leave policy, see their own documents. Carries no approval rights and no cross-zone reach.',
                'approval_tier' => 0,
                'is_final_authority' => false,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionIds = DB::table('permissions')
            ->whereIn('code', self::CODES)
            ->pluck('id', 'code');

        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', 'SELF_SERVICE')
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                // role_permissions is a pure pivot: role_id + permission_id, no
                // timestamps.
                DB::table('role_permissions')->insert([
                    'role_id' => 'SELF_SERVICE',
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $assigned = DB::table('employee_roles')
            ->where('role_id', 'SELF_SERVICE')
            ->pluck('employee_id')
            ->all();

        $rows = [];
        foreach (DB::table('employees')->where('is_active', 1)->pluck('id') as $employeeId) {
            if (in_array($employeeId, $assigned, true)) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'role_id' => 'SELF_SERVICE',
                // Unscoped on purpose: these four codes are not zone or department
                // bound, so pinning a scope here would be misleading.
                'department_id' => null,
                'scope_zone_id' => null,
                'source' => 'migration',
                'granted_by' => null,
                'effective_from' => $now->toDateString(),
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('employee_roles')->insert($chunk);
        }
    }

    public function down(): void
    {
        if (DB::table('roles')->count() === 0) {
            return;
        }

        // Restores the previous behaviour exactly: the role goes away and the
        // unconditional merge comes back, so a rollback cannot strand an
        // employee who was relying on the floor.
        DB::table('employee_roles')->where('role_id', 'SELF_SERVICE')->delete();
        DB::table('role_permissions')->where('role_id', 'SELF_SERVICE')->delete();
        DB::table('roles')->where('id', 'SELF_SERVICE')->delete();
    }
};
