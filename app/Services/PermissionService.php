<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * RBAC resolution mirroring the frontend permission model: a user may perform
 * `module.action` (e.g. requests.create, reports.view) when an active
 * employee_role grants a role that carries that permission. Granular codes
 * (approve_zone, view_medical_documents, edit_policy, view_gender_reports) are
 * just extra permission codes checked the same way, e.g.
 * documents.view_medical_documents.
 *
 * There is deliberately no wildcard. `is_final_authority` used to short-circuit
 * to `['*']`, which meant a role holding it silently received every code that
 * existed *and every code added later*, plus org-wide scope on any module whose
 * name happened to appear in a `can()` call. Authority is now expressed the
 * same way as everything else: by enumerated `role_permissions` rows, so the RBAC
 * matrix shows exactly what the role can do. Org-wide reach still comes only
 * from the three `*.view_cross_zone` codes, which BREAK_GLASS also carries.
 */
class PermissionService
{
    /**
     * Baseline self-service codes, now expressed as the SELF_SERVICE role rather
     * than a merge.
     *
     * This used to be array_merge()d into every user's set inside codesFor(),
     * which made the four codes impossible to revoke: removing `policies.view`
     * from a role in the matrix left the holder still holding it, because the
     * merge re-added it on the next read. The RBAC matrix has to be the whole
     * truth about what someone may do, so the floor is an ordinary role
     * (migration 2026_10_05_090000) and this list is now only the seeder's and
     * the tests' reference for what that role carries.
     *
     * Deliberately absent: approve/reject/return/export, every reports.* and
     * admin.* code, documents.create, and all view_cross_zone codes.
     */
    public const SELF_SERVICE = [
        'requests.view',
        'requests.create',
        'requests.view_own',
        'requests.edit_own',
        'requests.submit',
        'requests.withdraw',
        'policies.view',
        'documents.view',
        'staff.view_own',
        'leave_plans.view_own',
        'leave_plans.create_own',
        'leave_plans.select_own',
        'leave_waivers.create_own',
        'leave_waivers.view_own',
        'leave_returns.report_own',
        'leave_returns.view_own',
        'leave_payments.view_own',
        'leave_documents.view_own',
        'leave_documents.download_own',
        'reports.view_own',
        'notifications.view_own',
        'notifications.manage_preferences',
        'approval_workflows.view',
        'case_handovers.view',
        'case_handovers.create',
        'case_handovers.accept',
        'case_handovers.reject',
        'case_handovers.complete',
    ];

    /** Id of the role that carries the self-service floor. */
    public const SELF_SERVICE_ROLE = 'SELF_SERVICE';

    /**
     * Emergency role for account recovery. It carries the same enumerated
     * profile as FINAL_AUTHORITY but may only ever be held on a bounded
     * assignment, so a break-glass grant expires on its own instead of
     * requiring someone to remember to remove it.
     */
    public const BREAK_GLASS_ROLE = 'BREAK_GLASS';

    /** Longest window a BREAK_GLASS assignment may be given. */
    public const BREAK_GLASS_MAX_DAYS = 30;

    /**
     * @return array<int,string> permission codes granted via active roles
     */
    public function codesFor(User $user): array
    {
        $employee = $user->employee;
        if (! $employee) {
            return [];
        }

        return Cache::remember('perms:'.$user->id, 60, function () use ($employee) {
            $roleIds = $this->activeAssignments($employee->id)
                ->pluck('role_id')
                ->unique();

            $codes = $this->grantablePermissions()
                ->whereIn('id', function ($q) use ($roleIds) {
                    $q->select('permission_id')->from('role_permissions')->whereIn('role_id', $roleIds);
                })->pluck('code')->all();

            // No floor is merged in here. A user's codes are exactly what their
            // roles grant, so the RBAC matrix is the single source of truth and
            // every code on it is revocable.
            return array_values(array_unique($codes));
        });
    }

    /**
     * Effective access as permission+scope pairs, which is what authorization
     * actually consumes.
     *
     * codesFor() answers "may" and deliberately carries no scope; this method
     * answers "may, where" by pairing every code with the zone/department of the
     * role assignment that granted it. Keep both: mixing scope into codesFor()
     * would hide the capability from callers that need the bare code.
     *
     * Uncached on purpose — diagnostics must read live rows.
     *
     * @return array<int,array{code:string,zone_ids:array<int,string>,department_ids:array<int,string>,source:string}>
     */
    public function effectiveGrants(User $user): array
    {
        $employee = $user->employee;
        if (! $employee) {
            return [];
        }

        $assignments = $this->activeAssignments($employee->id);

        $out = [];
        $seen = [];

        foreach ($assignments as $assignment) {
            $roleId = $assignment->role_id;
            $zoneIds = $assignment->scope_zone_id ? [$assignment->scope_zone_id] : [];
            $deptIds = $assignment->department_id ? [$assignment->department_id] : [];

            // The self-service assignment is deliberately unscoped in the
            // database, and an unscoped grant means organisation-wide. Its four
            // codes are about *your own* leave, so the own-record ceiling is
            // applied here instead. This is a scope rule, not a capability rule:
            // it narrows what the role can reach and never grants a code, so the
            // matrix stays the only source of authority.
            if ($roleId === self::SELF_SERVICE_ROLE) {
                $zoneIds = [];
                $deptIds = ['own'];
            }

            foreach ($this->codesForRole($roleId) as $code) {
                $key = $code.'|'.implode(',', $zoneIds).'|'.implode(',', $deptIds);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $out[] = [
                    'code' => $code,
                    'zone_ids' => $zoneIds,
                    'department_ids' => $deptIds,
                    'source' => 'role:'.$roleId,
                ];
            }
        }

        return $out;
    }

    /**
     * Only codes that are implemented and active can ever grant. This is the
     * enforcement point for the lifecycle (audit §6.2): a `planned` code may be
     * listed in the catalog and rendered in the matrix, but it is unreachable
     * from any role, so flipping its switch cannot imply a capability nothing
     * checks.
     *
     * @return Builder<Permission>
     */
    protected function grantablePermissions()
    {
        return Permission::where('active', true)->where('status', 'implemented');
    }

    /** @return Collection<int,string> */
    public function codesForRole(string $roleId)
    {
        return $this->grantablePermissions()
            ->whereIn('id', function ($q) use ($roleId) {
                $q->select('permission_id')->from('role_permissions')->where('role_id', $roleId);
            })->pluck('code');
    }

    /**
     * Role assignments in their effective window. Every scope calculation must
     * go through here so an expired grant cannot silently widen access.
     *
     * Two further conditions are enforced here rather than at each call site:
     * the assignment itself must be active (an individually withdrawn grant), and
     * the role must be active (an archived role grants nothing to anyone, however
     * many rows still reference it).
     *
     * @return Collection<int,EmployeeRole>
     */
    public function activeAssignments(string $employeeId)
    {
        return EmployeeRole::where('employee_id', $employeeId)
            ->where('active', true)
            ->whereHas('role', fn ($q) => $q->where('active', true))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->get();
    }

    public function can(User $user, string $module, string $action): bool
    {
        return in_array($module.'.'.$action, $this->codesFor($user), true);
    }

    public function canAny(User $user, string $module, array $actions): bool
    {
        foreach ($actions as $action) {
            if ($this->can($user, $module, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the memoised codes for one user. Called whenever a grant changes
     * (role permissions edited, roles assigned/revoked, role deleted) so the
     * new access level applies immediately instead of after the 60s TTL.
     */
    public function forget(?User $user): void
    {
        if ($user) {
            Cache::forget('perms:'.$user->id);
        }
    }

    /** Drop the memoised codes of everyone currently holding the given role. */
    public function forgetHoldersOf(string $roleId): void
    {
        $userIds = Employee::whereIn('id', EmployeeRole::where('role_id', $roleId)->pluck('employee_id'))
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            Cache::forget('perms:'.$userId);
        }
    }

    public function rolesFor(User $user): array
    {
        return EmployeeRole::where('employee_id', $user->employee?->id)
            ->with('role')
            ->get()
            ->map(fn (EmployeeRole $er) => [
                'role_id' => $er->role_id,
                'name' => $er->role?->name,
                'department_id' => $er->department_id,
                'zone_id' => $er->scope_zone_id,
                'effective_from' => $er->effective_from?->toDateString(),
                'effective_to' => $er->effective_to?->toDateString(),
            ])
            ->all();
    }

    /**
     * Every assignment path funnels break-glass grants through here so the
     * role can never be granted open-ended, whichever endpoint was used.
     *
     * @param  array<int,string>  $roleIds
     * @return array<int,string> the subset that is BREAK_GLASS
     */
    public function breakGlassIn(array $roleIds): array
    {
        return array_values(array_filter(
            $roleIds,
            fn ($roleId) => $roleId === self::BREAK_GLASS_ROLE
        ));
    }

    /**
     * The `effective_to` a break-glass grant must be written with. Callers that
     * try to pass an open-ended or over-long window must clamp to this rather
     * than write null.
     */
    public function breakGlassExpiry(): string
    {
        return now()->addDays(self::BREAK_GLASS_MAX_DAYS)->toDateString();
    }

    /**
     * May $actor hand out $roleId to somebody?
     *
     * Two independent guards, because `admin.create` / `admin.update` used to
     * accept any role id at face value. One request could therefore mint a
     * FINAL_AUTHORITY holder - 34 permissions including all four
     * `*.view_cross_zone` codes, i.e. organisation-wide reach over every
     * module - which is a privilege escalation from "can administer employees"
     * to "can see and approve everyone's leave".
     *
     * 1. A role flagged is_final_authority is never grantable through the API.
     *    Same reasoning as RoleController refusing to *set* that flag: final
     *    authority is appointed by migration, not administered by an admin who
     *    might be an HR officer. Without this, HR (which carries the same 34
     *    codes) could mint FINAL_AUTHORITY under the subset rule below, since an
     *    equal set is a subset.
     *
     * 2. Otherwise the role's grantable codes must be a subset of the actor's
     *    own. Nobody can create authority they do not already hold. This is
     *    what actually stops a narrow admin from reaching upward.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function canGrantRole(User $actor, string $roleId): array
    {
        $role = Role::find($roleId);

        if (! $role) {
            return ['ok' => false, 'reason' => "Role \"{$roleId}\" does not exist."];
        }

        if (! $role->active) {
            return ['ok' => false, 'reason' => "Role \"{$roleId}\" is archived. Restore it before granting."];
        }

        if ($role->is_final_authority) {
            return ['ok' => false, 'reason' => 'Final authority cannot be granted through the API. It is appointed by migration only.'];
        }

        $roleCodes = $this->codesForRole($roleId)->all();
        $actorCodes = $this->codesFor($actor);

        // codesForRole() is already filtered to grantable codes, so a planned or
        // deactivated code cannot be smuggled in through the role either.
        $excess = array_values(array_diff($roleCodes, $actorCodes));

        if ($excess !== []) {
            return [
                'ok' => false,
                'reason' => "You cannot grant \"{$roleId}\" because it carries permissions you do not hold: "
                    .implode(', ', $excess),
            ];
        }

        return ['ok' => true, 'reason' => null];
    }
}
