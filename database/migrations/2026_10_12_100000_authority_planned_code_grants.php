<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant the governance-normalization planned codes to the authority roles.
 *
 * The 2026_10_12_090000 catalog migration shipped listing these grants, but
 * the authority lists were completed afterwards: planned codes (no endpoint
 * yet) must still satisfy the "every code is held by at least one active
 * role" invariant while staying unenforceable (grantablePermissions excludes
 * them, so they never resolve into codesFor()) and ungrantable via the API
 * (syncPermissions 422s). Fresh installs get them from the catalog migration
 * itself; this follow-up converges databases migrated in between.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private array $planned = [
        'approval_workflows.delete',
        'approval_workflows.activate',
        'approval_workflows.archive',
        'approval_workflows.test',
        'case_handovers.cancel',
        'requests.view_department',
        'requests.view_zone',
        'requests.cancel',
        'policies.update',
        'policies.archive',
        'policies.publish',
        'staff.view_department',
        'staff.view_zone',
    ];

    /** @var array<int,string> */
    private array $roles = ['FINAL_AUTHORITY', 'BREAK_GLASS'];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('code', $this->planned)->pluck('id', 'code');

        foreach ($this->roles as $roleId) {
            if (! DB::table('roles')->where('id', $roleId)->exists()) {
                continue;
            }

            foreach ($this->planned as $code) {
                if (! isset($ids[$code])) {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $ids[$code])
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $ids[$code],
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', $this->planned)->pluck('id')->all();

        DB::table('role_permissions')
            ->whereIn('role_id', $this->roles)
            ->whereIn('permission_id', $ids)
            ->delete();
    }
};
