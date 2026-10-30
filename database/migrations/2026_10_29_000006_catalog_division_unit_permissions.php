<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organization refactor — retire the standalone division/unit permission codes.
 *
 * An earlier draft catalogued `divisions.*` and `units.*` because the routes
 * were originally gated on them. During the refactor the division/unit CRUD was
 * moved onto the same `admin.*` gate that already protects zones, departments
 * and positions (so an admin who can open Admin & Org can maintain the whole
 * structure without a second, un-migrated permission family). The old codes
 * therefore gate nothing and must not linger as decorative switches in the
 * matrix or the audit report.
 *
 * This migration is a safe, idempotent cleanup: on a fresh database (where the
 * codes were never created) every statement is a no-op.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private array $modules = ['divisions', 'units'];

    public function up(): void
    {
        $this->purge();
    }

    public function down(): void
    {
        // Intentionally empty: this migration only removes orphaned codes that
        // no longer back any route, policy or `can()` call. Re-creating them
        // would reintroduce decorative permissions.
    }

    private function purge(): void
    {
        $codes = [];
        foreach ($this->modules as $module) {
            foreach (['view', 'create', 'update', 'delete', 'assign_head', 'view_employees'] as $action) {
                $codes[] = "{$module}.{$action}";
            }
        }

        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('modules')->whereIn('id', $this->modules)->delete();
    }
};
