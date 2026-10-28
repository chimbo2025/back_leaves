<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restore two permission codes that were removed by the prune migration,
 * so that the frontend RBAC matrix can display them honestly and the
 * specification's "do not remove buttons" requirement can be honoured.
 *
 * Codes added:
 *   policies.create      – create a new leave type (gated on admin.create)
 *   documents.delete     – delete a request document (gated on admin.delete)
 *
 * Idempotent: firstOrCreate guarantees no duplicate rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(
            ['code' => 'policies.create'],
            ['module' => 'policies', 'action' => 'create', 'label' => 'create', 'hint' => 'Create a new leave type']
        );

        Permission::firstOrCreate(
            ['code' => 'documents.delete'],
            ['module' => 'documents', 'action' => 'delete', 'label' => 'delete', 'hint' => 'Delete a request document']
        );

        // Grant them to the two authority roles.
        //
        // This was missed originally and it matters: the enumerate migration runs
        // BEFORE this one, so on an existing database the authority roles were
        // already holding "every catalogued code" - as it stood then. Adding two
        // codes to the catalog afterwards without extending those grants left
        // FINAL_AUTHORITY and BREAK_GLASS one code short of the catalog on every
        // upgraded install, while a freshly seeded database granted all 23. The
        // matrix would show the DPP lacking policies.create / documents.delete,
        // which is exactly the sort of mismatch this work exists to remove.
        if (DB::table('roles')->count() === 0) {
            // Fresh install: roles do not exist yet, and DatabaseSeeder hands both
            // authority roles the full enumerated catalog afterwards.
            return;
        }

        $ids = Permission::whereIn('code', ['policies.create', 'documents.delete'])->pluck('id');

        // FINAL_AUTHORITY and BREAK_GLASS are the whole catalog. HR is the other
        // role the seed matrix gives both codes to, and it is the role that
        // actually administers policy authoring and document administration, so
        // leaving it out would make POST /api/policies (gated on policies.create)
        // unreachable for the one team that is supposed to use it.
        foreach (['FINAL_AUTHORITY', 'BREAK_GLASS', 'HR'] as $roleId) {
            if (! DB::table('roles')->where('id', $roleId)->exists()) {
                continue;
            }

            $held = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();

            $rows = $ids->reject(fn ($id) => in_array($id, $held, true))
                ->map(fn ($id) => ['role_id' => $roleId, 'permission_id' => $id])
                ->values()
                ->all();

            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        $ids = Permission::whereIn('code', ['policies.create', 'documents.delete'])->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }

        // On rollback, remove the two codes we just added.
        // We keep the four other codes that the prune migration removed,
        // because those were explicitly deemed decorative.
        Permission::whereIn('code', ['policies.create', 'documents.delete'])->delete();
    }
};
