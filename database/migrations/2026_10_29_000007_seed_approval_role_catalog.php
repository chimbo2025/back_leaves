<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workflow refactor — make the dynamic approval-role catalog real.
 *
 * `approval_roles`, `employee_approval_roles`, `workflow_steps.approval_role_id`
 * and `delegations.approval_role_id` were introduced by 2026_10_26_000001..4,
 * but nothing ever populated the catalog or mirrored the existing role grants.
 * A step therefore pointed at an approval role that did not exist, and the
 * resolver had no holder to return.
 *
 * This migration is additive and idempotent:
 *
 *   1. inserts the default catalog (no rows are updated destructively);
 *   2. backfills workflow_steps.approval_role_id from legacy role_required;
 *   3. backfills delegations.approval_role_id from legacy role_id;
 *   4. mirrors existing employee_roles grants into employee_approval_roles so
 *      current approvers keep resolving without re-appointment.
 *
 * On a fresh database the tables are empty when migrations run; DatabaseSeeder
 * repeats the same mirroring after it has created employees.
 */
return new class extends Migration
{
    /** @var array<int,array<string,mixed>> */
    private array $catalog = [
        ['id' => 'supervisor', 'code' => 'supervisor', 'name' => 'Supervisor', 'description' => 'Immediate supervisor from the reporting line.', 'resolution_type' => 'supervisor', 'is_system_protected' => true],
        ['id' => 'department_head', 'code' => 'department_head', 'name' => 'Department Head', 'description' => 'Head of the employee department.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => true],
        ['id' => 'zonal_head', 'code' => 'zonal_head', 'name' => 'Zonal Head', 'description' => 'Zone / regional head for offices outside HQ.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => true],
        ['id' => 'unit_head', 'code' => 'unit_head', 'name' => 'Unit Head', 'description' => 'Head of an organizational unit.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false],
        ['id' => 'division_head', 'code' => 'division_head', 'name' => 'Division Head', 'description' => 'Head of a division within a department.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false],
        ['id' => 'hr_reviewer', 'code' => 'hr_reviewer', 'name' => 'HR Reviewer', 'description' => 'Verifies policy compliance and leave balance.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => true],
        ['id' => 'hr_recorder', 'code' => 'hr_recorder', 'name' => 'HR Recorder', 'description' => 'Records documentation without approval authority.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false],
        ['id' => 'deputy_dpp', 'code' => 'deputy_dpp', 'name' => 'Deputy DPP', 'description' => 'Deputy Director of Public Prosecutions.', 'resolution_type' => 'both', 'is_system_protected' => false],
        ['id' => 'final_authority', 'code' => 'final_authority', 'name' => 'Final Authority', 'description' => 'Final sign-off — DPP authority.', 'resolution_type' => 'both', 'is_system_protected' => true],
        ['id' => 'accounting_reviewer', 'code' => 'accounting_reviewer', 'name' => 'Accounting Reviewer', 'description' => 'Reviews and processes leave-payment settlements.', 'resolution_type' => 'employee_approval_roles', 'is_system_protected' => false],
    ];

    /** @var array<string,string> */
    private array $legacyRoles = [
        'SUPERVISOR' => 'supervisor',
        'DEPT_HEAD' => 'department_head',
        'ZONAL_HEAD' => 'zonal_head',
        'UNIT_HEAD' => 'unit_head',
        'DIVISION_HEAD' => 'division_head',
        'HR' => 'hr_reviewer',
        'HR_RECORDER' => 'hr_recorder',
        'DEPUTY_DPP' => 'deputy_dpp',
        'FINAL_AUTHORITY' => 'final_authority',
        'ACCOUNTING' => 'accounting_reviewer',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->catalog as $role) {
            $exists = DB::table('approval_roles')->where('id', $role['id'])->exists();

            if (! $exists) {
                DB::table('approval_roles')->insert($role + [
                    'name_sw' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ($this->legacyRoles as $legacy => $code) {
            DB::table('workflow_steps')
                ->where('role_required', $legacy)
                ->whereNull('approval_role_id')
                ->update(['approval_role_id' => $code]);

            DB::table('delegations')
                ->where('role_id', $legacy)
                ->whereNull('approval_role_id')
                ->update(['approval_role_id' => $code]);
        }

        $this->mirrorEmployeeRoles($now);
    }

    /**
     * Copy legacy employee_roles grants into the dynamic catalog.
     *
     * Only roles that a workflow step can name are mirrored; SELF_SERVICE and
     * other permission-only roles are deliberately ignored.
     */
    private function mirrorEmployeeRoles($now): void
    {
        $grants = DB::table('employee_roles')
            ->where('active', true)
            ->whereIn('role_id', array_keys($this->legacyRoles))
            ->get();

        foreach ($grants as $grant) {
            $code = $this->legacyRoles[$grant->role_id];

            DB::table('employee_approval_roles')->updateOrInsert(
                [
                    'employee_id' => $grant->employee_id,
                    'approval_role_id' => $code,
                    'organizational_unit_id' => $grant->department_id,
                    'zone_id' => $grant->scope_zone_id,
                ],
                [
                    'id' => $this->grantId($grant->employee_id, $code, $grant->department_id, $grant->scope_zone_id),
                    'is_primary' => false,
                    'effective_from' => $grant->effective_from ?? '2020-01-01',
                    'effective_to' => $grant->effective_to,
                    'source' => $grant->source ?? 'migrated',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function grantId(string $employeeId, string $code, ?string $departmentId, ?string $zoneId): string
    {
        $scope = $departmentId ?? $zoneId ?? 'global';

        return 'ear_'.$employeeId.'_'.$code.'_'.$scope;
    }

    public function down(): void
    {
        // Catalog and mirrored grants are reference data; removing them would
        // break workflow steps and delegations created after the upgrade.
    }
};
