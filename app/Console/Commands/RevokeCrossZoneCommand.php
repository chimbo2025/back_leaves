<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Review (and optionally remove) `<module>.view_cross_zone` from roles that
 * should not have organisation-wide reach.
 *
 * Organisation-wide visibility comes from this one permission and nothing
 * else (see AccessScopeService). Roles created in the admin screen can be
 * handed it by hand, which makes every holder see every department's data.
 *
 * Without --apply the command is a read-only report. With --apply it detaches
 * the code from every role not listed in --keep, clears the permission cache
 * of that role's holders and writes one audit entry per role. It prints the
 * INSERT statement that restores exactly what it removed.
 */
class RevokeCrossZoneCommand extends Command
{
    protected $signature = 'permissions:revoke-cross-zone
        {--module=requests : Module whose view_cross_zone code is reviewed}
        {--keep=HR,FINAL_AUTHORITY,BREAK_GLASS : Comma-separated role ids that keep the code}
        {--apply : Remove the code from the other roles (default is a read-only report)}';

    protected $description = 'List roles holding <module>.view_cross_zone and optionally remove it from all but an allow-list';

    public function handle(PermissionService $permissions, AuditService $audit): int
    {
        $module = (string) $this->option('module');
        $code = $module.'.view_cross_zone';
        $keep = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('keep')))));

        $permission = Permission::where('code', $code)->first();

        if (! $permission) {
            $this->error("Permission {$code} does not exist.");

            return self::FAILURE;
        }

        $holderRoleIds = DB::table('role_permissions')
            ->where('permission_id', $permission->id)
            ->pluck('role_id')
            ->all();

        if ($holderRoleIds === []) {
            $this->info("No role holds {$code}.");

            return self::SUCCESS;
        }

        $rows = [];
        $toRevoke = [];

        foreach (Role::whereIn('id', $holderRoleIds)->orderBy('id')->get() as $role) {
            $users = DB::table('employee_roles')
                ->where('role_id', $role->id)
                ->where('active', true)
                ->distinct()
                ->count('employee_id');

            $kept = in_array($role->id, $keep, true);
            $rows[] = [$role->id, $role->name, $users, $kept ? 'KEEP' : 'REVOKE'];

            if (! $kept) {
                $toRevoke[] = $role->id;
            }
        }

        $this->line("Roles holding {$code}:");
        $this->table(['Role id', 'Name', 'Active holders', 'Action'], $rows);
        $this->line('Keep list: '.($keep === [] ? '(empty)' : implode(', ', $keep)));

        if ($toRevoke === []) {
            $this->info('Nothing to revoke.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn('Read-only report. Re-run with --apply to remove the code from the REVOKE roles.');
            $this->warn('Holders of those roles keep any explicit zone/department/division/unit grant and any workflow assignment, but lose organisation-wide reach.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toRevoke, $permission, $code, $audit) {
            foreach ($toRevoke as $roleId) {
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permission->id)
                    ->delete();

                $audit->append('role', $roleId, 'permission_revoke', null, [
                    'permission' => $code,
                    'source' => 'permissions:revoke-cross-zone',
                ]);
            }
        });

        foreach ($toRevoke as $roleId) {
            $permissions->forgetHoldersOf($roleId);
        }

        $values = collect($toRevoke)
            ->map(fn (string $roleId) => "('{$roleId}', {$permission->id})")
            ->implode(', ');

        $this->info('Removed '.$code.' from: '.implode(', ', $toRevoke));
        $this->line('To undo, run this SQL:');
        $this->line("INSERT INTO role_permissions (role_id, permission_id) VALUES {$values};");

        return self::SUCCESS;
    }
}
