<?php

namespace App\Services;

use App\Models\ApprovalRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Read-only permission audit.
 *
 * The RBAC matrix answers "what can this role do". This report answers the
 * questions an administrator or auditor asks about the matrix itself:
 *
 *   - which catalogued codes are actually grantable and enforced;
 *   - which roles hold them, and how many people hold each role;
 *   - which dynamic approval roles the workflow engine can resolve to;
 *   - where reality has drifted from configuration: grants pointing at
 *     archived roles, expired assignments still marked active, unscoped
 *     structural heads, workflow steps with no role at all.
 *
 * Every query is live (no permission cache) and the service only reads.
 */
class PermissionAuditService
{
    public function __construct(protected PermissionService $permissions) {}

    public function report(): array
    {
        $today = now()->toDateString();

        $permissions = Permission::orderBy('module')->orderBy('action')->get();
        $roles = Role::with('permissions')->orderBy('name')->get();

        $grantableCodes = $permissions->filter(fn (Permission $p) => $p->isGrantable())->pluck('code')->all();

        $rolesCountByPermission = DB::table('role_permissions')
            ->select('permission_id', DB::raw('COUNT(*) as roles_count'))
            ->groupBy('permission_id')
            ->pluck('roles_count', 'permission_id');

        $holdersByPermission = DB::table('role_permissions')
            ->join('employee_roles', 'employee_roles.role_id', '=', 'role_permissions.role_id')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.active', true)
            ->where('employee_roles.active', true)
            ->where('permissions.active', true)
            ->where('permissions.status', 'implemented')
            ->where(fn ($q) => $q->whereNull('employee_roles.effective_from')->orWhereDate('employee_roles.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('employee_roles.effective_to')->orWhereDate('employee_roles.effective_to', '>=', $today))
            ->select('permissions.code', DB::raw('COUNT(DISTINCT employee_roles.employee_id) as holders'))
            ->groupBy('permissions.code')
            ->pluck('holders', 'permissions.code');

        $holdersByRole = DB::table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->where('roles.active', true)
            ->where('employee_roles.active', true)
            ->where(fn ($q) => $q->whereNull('employee_roles.effective_from')->orWhereDate('employee_roles.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('employee_roles.effective_to')->orWhereDate('employee_roles.effective_to', '>=', $today))
            ->select('employee_roles.role_id', DB::raw('COUNT(DISTINCT employee_roles.employee_id) as holders'))
            ->groupBy('employee_roles.role_id')
            ->pluck('holders', 'employee_roles.role_id');

        $approvalRoles = $this->approvalRoles();
        $anomalies = $this->anomalies($today, $permissions, $grantableCodes);

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'permissions' => $permissions->count(),
                'grantable_permissions' => count($grantableCodes),
                'roles' => $roles->count(),
                'active_roles' => $roles->where('active', true)->count(),
                'employees' => DB::table('employees')->where('is_active', true)->count(),
                'active_employee_roles' => DB::table('employee_roles')->where('active', true)->count(),
                'approval_roles' => count($approvalRoles),
                'workflow_steps' => DB::table('workflow_steps')->count(),
                'anomalies' => count($anomalies),
            ],
            'permissions' => $permissions->map(fn (Permission $p) => [
                'code' => $p->code,
                'module' => $p->module,
                'action' => $p->action,
                'label' => $p->label,
                'status' => $p->status,
                'active' => (bool) $p->active,
                'is_grantable' => $p->isGrantable(),
                'enforcement_reference' => $p->enforcement_reference,
                'roles_count' => (int) ($rolesCountByPermission[$p->id] ?? 0),
                'holders_count' => (int) ($holdersByPermission[$p->code] ?? 0),
            ])->values()->all(),
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
                'active' => (bool) $r->active,
                'is_system' => (bool) $r->is_system,
                'is_final_authority' => (bool) $r->is_final_authority,
                'permissions' => $r->permissions->pluck('code')->sort()->values()->all(),
                'holders_count' => (int) ($holdersByRole[$r->id] ?? 0),
            ])->values()->all(),
            'approval_roles' => $approvalRoles,
            'force_delete_holders' => $this->forceDeleteHolders($today),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Configuration drift that needs a human decision. Severity is a rendering
     * hint: `critical` means a capability is advertised but cannot work,
     * `warning` means access is wider or older than intended, `info` is
     * migration debt that is safe to leave for now.
     *
     * @param  \Illuminate\Support\Collection<int,Permission>  $permissions
     * @param  array<int,string>  $grantableCodes
     * @return array<int,array<string,mixed>>
     */
    protected function anomalies(string $today, $permissions, array $grantableCodes): array
    {
        $out = [];

        // A grantable code with no enforcement point is a decorative switch.
        foreach ($permissions as $permission) {
            if ($permission->isGrantable() && blank($permission->enforcement_reference)) {
                $out[] = $this->anomaly('warning', 'permission_unenforced',
                    "{$permission->code} is grantable but has no enforcement reference.",
                    'permission', $permission->code);
            }
        }

        // role_permissions rows for codes the lifecycle now blocks.
        $blockedGrants = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where(fn ($q) => $q->where('permissions.active', false)->orWhere('permissions.status', '!=', 'implemented'))
            ->select('permissions.code', DB::raw('COUNT(*) as rows_count'))
            ->groupBy('permissions.code')
            ->get();
        foreach ($blockedGrants as $row) {
            $out[] = $this->anomaly('critical', 'role_grant_not_grantable',
                "{$row->code} is assigned to {$row->rows_count} role(s) but can no longer be granted.",
                'permission', $row->code);
        }

        // Live assignments that resolve to nothing.
        $archivedAssignments = DB::table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->where('employee_roles.active', true)
            ->where('roles.active', false)
            ->select('roles.id as role_id', 'roles.name', DB::raw('COUNT(DISTINCT employee_roles.employee_id) as holders'))
            ->groupBy('roles.id', 'roles.name')
            ->get();
        foreach ($archivedAssignments as $row) {
            $out[] = $this->anomaly('warning', 'archived_role_assignment',
                "Archived role {$row->name} is still held by {$row->holders} employee(s).",
                'role', $row->role_id);
        }

        $expiredAssignments = DB::table('employee_roles')
            ->where('active', true)
            ->whereNotNull('effective_to')
            ->whereDate('effective_to', '<', $today)
            ->select('role_id', DB::raw('COUNT(*) as rows_count'))
            ->groupBy('role_id')
            ->get();
        foreach ($expiredAssignments as $row) {
            $out[] = $this->anomaly('warning', 'expired_active_assignment',
                "{$row->rows_count} active assignment(s) of {$row->role_id} are past their effective_to date.",
                'role', $row->role_id);
        }

        // Structural authority without a scope grants only own records.
        $unscopedHeads = DB::table('employee_roles')
            ->whereIn('role_id', ['DEPT_HEAD', 'ZONAL_HEAD'])
            ->whereNull('department_id')
            ->whereNull('scope_zone_id')
            ->select('employee_id', 'role_id')
            ->get();
        foreach ($unscopedHeads as $row) {
            $out[] = $this->anomaly('warning', 'unscoped_structural_head',
                "{$row->employee_id} holds {$row->role_id} without a department/zone scope.",
                'employee', $row->employee_id);
        }

        // Workflow steps that cannot resolve to any approval role.
        $rolelessSteps = DB::table('workflow_steps')
            ->whereNull('approval_role_id')
            ->whereNull('role_required')
            ->get(['id', 'workflow_def_id']);
        foreach ($rolelessSteps as $row) {
            $out[] = $this->anomaly('critical', 'workflow_step_without_role',
                'A workflow step has neither an approval role nor a legacy role pointer.',
                'workflow_step', (string) $row->id);
        }

        $inactiveRoleSteps = DB::table('workflow_steps')
            ->join('approval_roles', 'approval_roles.id', '=', 'workflow_steps.approval_role_id')
            ->where('approval_roles.is_active', false)
            ->get(['workflow_steps.id', 'approval_roles.code']);
        foreach ($inactiveRoleSteps as $row) {
            $out[] = $this->anomaly('critical', 'workflow_step_inactive_role',
                "A workflow step uses inactive approval role {$row->code}.",
                'workflow_step', (string) $row->id);
        }

        $legacySteps = DB::table('workflow_steps')
            ->whereNull('approval_role_id')
            ->whereNotNull('role_required')
            ->count();
        if ($legacySteps > 0) {
            $out[] = $this->anomaly('info', 'workflow_step_legacy_role',
                "{$legacySteps} workflow step(s) still resolve through the legacy role_required pointer.");
        }

        // Approval roles nobody configured a step for.
        $unusedRoles = DB::table('approval_roles')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('workflow_steps')
                ->whereColumn('workflow_steps.approval_role_id', 'approval_roles.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('workflow_steps')
                ->whereColumn('workflow_steps.fallback_approval_role_id', 'approval_roles.id'))
            ->get(['id', 'code']);
        foreach ($unusedRoles as $row) {
            $out[] = $this->anomaly('info', 'approval_role_unused',
                "Approval role {$row->code} is not referenced by any workflow step.",
                'approval_role', $row->id);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    protected function anomaly(string $severity, string $code, string $message, ?string $entityType = null, ?string $entityId = null): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    protected function approvalRoles(): array
    {
        $stepCounts = DB::table('workflow_steps')
            ->whereNotNull('approval_role_id')
            ->select('approval_role_id', DB::raw('COUNT(*) as steps_count'))
            ->groupBy('approval_role_id')
            ->pluck('steps_count', 'approval_role_id');

        $fallbackCounts = DB::table('workflow_steps')
            ->whereNotNull('fallback_approval_role_id')
            ->select('fallback_approval_role_id', DB::raw('COUNT(*) as steps_count'))
            ->groupBy('fallback_approval_role_id')
            ->pluck('steps_count', 'fallback_approval_role_id');

        $holderCounts = DB::table('employee_approval_roles')
            ->where('active', true)
            ->select('approval_role_id', DB::raw('COUNT(DISTINCT employee_id) as holders'))
            ->groupBy('approval_role_id')
            ->pluck('holders', 'approval_role_id');

        return ApprovalRole::orderBy('name')->get()->map(fn (ApprovalRole $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'resolution_type' => $r->resolution_type,
            'is_active' => (bool) $r->is_active,
            'is_system_protected' => (bool) $r->is_system_protected,
            'steps_count' => (int) ($stepCounts[$r->id] ?? 0),
            'fallback_steps_count' => (int) ($fallbackCounts[$r->id] ?? 0),
            'holders_count' => (int) ($holderCounts[$r->id] ?? 0),
        ])->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function forceDeleteHolders(string $today): array
    {
        return DB::table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'employee_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('employees', 'employees.id', '=', 'employee_roles.employee_id')
            ->whereIn('permissions.code', ['leave_requests.force_delete', 'employees.force_delete'])
            ->where('roles.active', true)
            ->where('employee_roles.active', true)
            ->where(fn ($q) => $q->whereNull('employee_roles.effective_from')->orWhereDate('employee_roles.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('employee_roles.effective_to')->orWhereDate('employee_roles.effective_to', '>=', $today))
            ->select([
                'employees.id as employee_id',
                'employees.full_name',
                'roles.id as role_id',
                'roles.name as role_name',
                'permissions.code',
                'employee_roles.effective_to',
            ])
            ->orderBy('employees.full_name')
            ->orderBy('permissions.code')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
