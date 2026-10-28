<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the `['*']` wildcard with an enumerated profile, and adds a
 * time-boxed break-glass role.
 *
 * The defect
 * ----------
 * `PermissionService::codesFor()` used to resolve any role carrying
 * `is_final_authority` to the sentinel `['*']`, and `can()` short-circuited on
 * it *before* inspecting module or action. Two consequences:
 *
 *   1. The role received every permission code that existed at the time plus
 *      every code added later, with no grant row recording it. The RBAC matrix
 *      displayed 7 codes for FINAL_AUTHORITY while it actually held all of
 *      them, so the console misrepresented the most powerful account in the
 *      system.
 *   2. `AccessScopeService::canViewAllZones()` asks `can($user, $module,
 *      'view_cross_zone')` per module. The sentinel matched first, so a role
 *      that named only three `view_cross_zone` codes was org-wide on every
 *      module string. The three explicitly seeded rows were dead code.
 *
 * Second route to the same outcome: `roles.store()` accepted
 * `is_final_authority: true` from any caller holding `admin.create`, and
 * `POST /api/org/employees` then handed the new employee every
 * `position_default_roles` row for that role. Two requests minted a superuser.
 * The flag is now immutable outside a migration — RoleController rejects any
 * attempt to set or clear it — which is strictly stronger than gating it.
 *
 * What this migration does
 * ------------------------
 * FINAL_AUTHORITY is granted every currently catalogued code explicitly, so its
 * effective access is byte-for-byte what `['*']` produced today, minus the
 * ability to absorb future codes silently. Nothing loses access; the matrix
 * simply becomes truthful.
 *
 * BREAK_GLASS carries the same profile for account recovery, but
 * `PermissionService::BREAK_GLASS_MAX_DAYS` forces every assignment path to
 * write a bounded `effective_to`, so the grant lapses on its own. It is not
 * assigned to anyone here; it exists to be granted deliberately.
 *
 * No employee_roles rows are created, so `effective_to` is left to the grant
 * paths, which clamp it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Upgrade path only. On a fresh database `migrate` runs before `db:seed`,
        // so roles do not exist yet and inserting role_permissions rows would
        // violate the FK. In that case DatabaseSeeder is what creates the roles
        // and grants them this same enumerated profile, so there is nothing to do
        // here.
        if (DB::table('roles')->count() === 0) {
            return;
        }

        if (! DB::table('roles')->where('id', 'BREAK_GLASS')->exists()) {
            DB::table('roles')->insert([
                'id' => 'BREAK_GLASS',
                'name' => 'Break-Glass Authority',
                'description' => 'Emergency recovery access. Identical reach to the Final Authority, but every grant is capped at 30 days so it cannot become a permanent standing privilege.',
                'approval_tier' => 5,
                'is_final_authority' => false,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->pluck('id');

        // Enumerate the profile for both authority roles. Filtering on what is
        // already held keeps this idempotent and preserves any narrower grant an
        // admin made deliberately in the matrix.
        foreach (['FINAL_AUTHORITY', 'BREAK_GLASS'] as $roleId) {
            $held = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();

            $rows = $permissionIds->reject(fn ($id) => in_array($id, $held, true))
                ->map(fn ($id) => [
                    'role_id' => $roleId,
                    'permission_id' => $id,
                ])
                ->values()
                ->all();

            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('roles')->count() === 0) {
            return;
        }

        // Restore the previous, deliberately-narrow FINAL_AUTHORITY grant set:
        // the three view_cross_zone codes plus the four codes the seeder gave it.
        $restore = [
            'reports.view',
            'reports.view_cross_zone',
            'reports.view_gender_reports',
            'requests.approve',
            'requests.view',
            'requests.view_cross_zone',
            'staff.view_cross_zone',
        ];

        DB::table('roles')->where('id', 'BREAK_GLASS')->delete();

        $keepIds = DB::table('permissions')->whereIn('code', $restore)->pluck('id');

        DB::table('role_permissions')
            ->where('role_id', 'FINAL_AUTHORITY')
            ->whereNotIn('permission_id', $keepIds)
            ->delete();
    }
};
