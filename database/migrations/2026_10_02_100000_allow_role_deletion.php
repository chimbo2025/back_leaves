<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes dynamic roles deletable.
 *
 * Two problems blocked `DELETE /api/roles/{role}`:
 *
 *  1. `admin.delete` did not exist in the permission catalog, so no role could
 *     ever be authorised to delete one. Added below and granted to HR only.
 *
 *  2. `delegations.role_id` and `workflow_assignments.assigned_role_id` used
 *     restrict-by-default foreign keys. Both tables are *assignments* of a role,
 *     so the grant should disappear with the role — the same semantics already
 *     used by employee_roles, position_default_roles and role_permissions.
 *     Switched to ON DELETE CASCADE; the referencing columns stay NOT NULL
 *     because cascading removes the rows instead of nulling them.
 *
 * `workflow_steps.role_required` is deliberately LEFT restrictive: it is
 * approval-chain configuration, not an assignment. Silently dropping a step would
 * corrupt live workflows, so RoleController::destroy() refuses with a 422 listing
 * the offending steps instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && ! DB::table('permissions')->where('code', 'admin.delete')->exists()) {
            DB::table('permissions')->insert([
                'code' => 'admin.delete',
                'module' => 'admin',
                'action' => 'delete',
                'label' => 'delete',
                'hint' => 'Delete dynamic roles, positions and org records.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('roles') && DB::table('roles')->where('id', 'HR')->exists()) {
            $permissionId = DB::table('permissions')->where('code', 'admin.delete')->value('id');

            if ($permissionId && ! DB::table('role_permissions')
                ->where('role_id', 'HR')->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert([
                    'role_id' => 'HR',
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // The FK rewrite below reads MySQL's information_schema, so it is
        // MySQL/MariaDB-only. SQLite (the test database) builds its schema
        // straight from the create migrations and needs no rewrite.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->cascadeForeign('delegations', 'role_id');
            $this->cascadeForeign('workflow_assignments', 'assigned_role_id');
        }
    }

    /** Make a restrictive FK cascading so the child row cannot outlive its parent. */
    protected function cascadeForeign(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        // Laravel names these `{table}_{column}_foreign`; ask the catalogue rather
        // than assuming, so a renamed/hand-rolled constraint cannot break migrate.
        $constraint = DB::selectOne(
            'SELECT kcu.CONSTRAINT_NAME AS name, rc.DELETE_RULE AS delete_rule
               FROM information_schema.KEY_COLUMN_USAGE kcu
               JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.TABLE_NAME = kcu.TABLE_NAME
              WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = ?
                AND kcu.COLUMN_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME = ?',
            [$table, $column, 'roles']
        );

        if (! $constraint || strtoupper($constraint->delete_rule) === 'CASCADE') {
            return;
        }

        $constraintName = $constraint->name;

        // ON DELETE CASCADE removes the child rows, so the column must stay NOT
        // NULL — no MODIFY is needed (and none should be applied, since it would
        // silently drop NOT NULL guarantees for live delegation data).
        Schema::table($table, function (Blueprint $blueprint) use ($constraintName) {
            $blueprint->dropForeign($constraintName);
        });

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->foreign($column)->references('id')->on('roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // The relaxed delete rules are left in place: restoring RESTRICT would
        // need a rebuild that can fail on rows inserted while cascading was
        // active. Only the permission is withdrawn (granted to HR only).
        $permissionId = DB::table('permissions')->where('code', 'admin.delete')->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
