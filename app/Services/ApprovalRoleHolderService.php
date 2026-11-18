<?php

namespace App\Services;

use App\Models\ApprovalRole;
use App\Models\Employee;
use App\Models\EmployeeApprovalRole;
use App\Models\EmployeeRole;
use App\Models\ReportingLine;
use App\Support\ApprovalRoleMap;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "who holds this approval role".
 *
 * The Workflow Builder's employee dropdown and the server-side validation of
 * a selected approver both use this service, so the list an administrator can
 * pick from is exactly the list the API accepts.
 *
 * Sources:
 *   - `employee_approval_roles` (catalog-era grants)
 *   - `employee_roles` mapped through ApprovalRoleMap (legacy grants)
 *   - active primary reporting lines for supervisor-style resolutions
 */
class ApprovalRoleHolderService
{
    public function resolveRole(string $idOrCode): ?ApprovalRole
    {
        $role = ApprovalRole::where('id', $idOrCode)
            ->orWhere('code', $idOrCode)
            ->first();

        if ($role) {
            return $role;
        }

        // Roles created in Admin settings live in the `roles` table, not
        // `approval_roles`. Synthesise an ApprovalRole on the fly so the
        // holder lookup still works for them.
        $systemRole = \App\Models\Role::where('id', $idOrCode)->first();
        if (! $systemRole) {
            $systemRole = \App\Models\Role::whereRaw('LOWER(id) = ?', [strtolower($idOrCode)])->first();
        }
        if ($systemRole) {
            return new ApprovalRole([
                'id' => (string) $systemRole->id,
                'code' => strtolower((string) $systemRole->id),
                'name' => $systemRole->name,
                'resolution_type' => 'employee_approval_roles',
                'is_active' => true,
                'is_system_protected' => false,
            ]);
        }

        if ($legacyCode = ApprovalRoleMap::approvalCodeFor($idOrCode)) {
            return ApprovalRole::where('code', $legacyCode)->first();
        }

        return null;
    }

    /**
     * Active employees who hold the role, ready for the builder dropdown.
     *
     * @return Collection<int, Employee>
     */
    public function holdersFor(ApprovalRole|string $role): Collection
    {
        $role = $role instanceof ApprovalRole ? $role : $this->resolveRole($role);

        if (! $role) {
            return collect();
        }

        // Explicit grants are the base of every role: catalog-era
        // (`employee_approval_roles`) plus legacy (`employee_roles`) grants.
        $explicit = array_values(array_unique(array_filter(array_merge(
            $this->explicitHolderIds($role->id),
            $this->legacyHolderIds($role->code),
        ))));

        if ($role->resolution_type === 'supervisor') {
            // A supervisor step is defined by the reporting line, so the line
            // is always included alongside any explicit grant.
            $ids = array_merge($explicit, $this->supervisorIds());
        } elseif ($explicit !== []) {
            // An explicit grant list is authoritative for other roles.
            // Reporting-line supervisors are never unioned on top of it: doing
            // so made the Final Authority dropdown list every supervisor in
            // the office instead of the people actually granted the role.
            $ids = $explicit;
        } elseif ($role->resolution_type === 'both') {
            // `both` falls back to the reporting line only when nobody was
            // granted the role explicitly (the documented resolution order).
            $ids = $this->supervisorIds();
        } else {
            $ids = [];
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return collect();
        }

        return Employee::whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get();
    }

    /** Does this employee hold the role through any configured source? */
    public function employeeHolds(Employee $employee, ApprovalRole|string $role): bool
    {
        $role = $role instanceof ApprovalRole ? $role : $this->resolveRole($role);

        if (! $role) {
            return false;
        }

        $explicit = array_merge(
            $this->explicitHolderIds($role->id),
            $this->legacyHolderIds($role->code),
        );

        if (in_array($employee->id, $explicit, true)) {
            return true;
        }

        if ($role->resolution_type === 'supervisor') {
            return in_array($employee->id, $this->supervisorIds(), true);
        }

        // A "both" role falls back to the reporting line only when the role
        // has no explicit holders at all — mirroring holdersFor() so the list
        // an administrator can pick from is exactly the list the API accepts.
        return $role->resolution_type === 'both'
            && $explicit === []
            && in_array($employee->id, $this->supervisorIds(), true);
    }

    /** @return list<string> */
    protected function explicitHolderIds(string $approvalRoleId): array
    {
        $today = now()->toDateString();

        return EmployeeApprovalRole::where('approval_role_id', $approvalRoleId)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->pluck('employee_id')
            ->all();
    }

    /** @return list<string> */
    protected function legacyHolderIds(string $approvalRoleCode): array
    {
        $today = now()->toDateString();

        // If this is a role from the `roles` table (Admin-created), look up
        // employees by the actual role id directly, bypassing the legacy map.
        $systemRole = \App\Models\Role::whereRaw('LOWER(id) = ?', [strtolower($approvalRoleCode)])->first();
        if ($systemRole) {
            $ids = EmployeeRole::where('role_id', $systemRole->id)
                ->where(function ($q) {
                    $q->whereNull('active')->orWhere('active', true);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                })
                ->pluck('employee_id')
                ->all();

            if ($ids !== []) {
                return $ids;
            }
        }

        $legacyIds = ApprovalRoleMap::legacyRoleIdsFor($approvalRoleCode);

        return EmployeeRole::whereIn('role_id', $legacyIds)
            ->where(function ($q) {
                $q->whereNull('active')->orWhere('active', true);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->pluck('employee_id')
            ->all();
    }

    /** @return list<string> */
    protected function supervisorIds(): array
    {
        $today = now()->toDateString();

        return ReportingLine::where('relationship_type', 'primary')
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->pluck('supervisor_employee_id')
            ->unique()
            ->values()
            ->all();
    }
}
