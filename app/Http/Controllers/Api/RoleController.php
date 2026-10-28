<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin RBAC console (frontend settings createRole/updateRole/deleteRole). System
 * roles are protected from flag mutation and deletion so the permission model
 * cannot be broken.
 */
class RoleController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected PermissionService $permissions,
    ) {}

    /**
     * Every role, archived ones included.
     *
     * Archived roles are returned on purpose: the matrix has to be able to show a
     * role and offer Restore, otherwise there is no way back from an archive. The
     * `active` flag travels with each row so the UI can render the distinction
     * instead of showing an editable switch for a role that grants nothing.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Role::with('permissions')->orderBy('name')->get(),
        ]);
    }

    /**
     * Full permission catalog for the matrix UI, sourced from the `modules` table
     * rather than from the free-text permissions.module column, so the matrix
     * groups by the real module list and carries the lifecycle flags.
     *
     * Planned and deactivated codes are included deliberately: a matrix that hides
     * them cannot show an admin that a capability exists but is not yet enforced.
     * is_grantable tells the UI which switches can actually be flipped.
     */
    public function permissions(): JsonResponse
    {
        $codes = Permission::orderBy('module')->orderBy('action')->get();

        return response()->json([
            'data' => $codes->map(fn (Permission $p) => $p->toMatrixArray())->values(),
            'modules' => DB::table('modules')
                ->orderBy('label')
                ->get()
                ->keyBy('id'),
        ]);
    }

    public function store(StoreRoleRequest $form): JsonResponse
    {
        // `is_final_authority` is not settable through the API at all. It used to
        // be accepted from any admin.create holder, and because the flag fed the
        // `['*']` sentinel that was a one-request superuser mint. The flag is now
        // pure identity: authority comes from enumerated role_permissions rows.
        if ($form->boolean('is_final_authority')) {
            throw ValidationException::withMessages([
                'is_final_authority' => 'Final authority is not assignable through the API. It is set by migration only.',
            ]);
        }

        $id = $form->input('id') ?: 'ROLE_'.strtoupper(Str::slug($form->input('name'), '_'));
        $role = Role::create([
            'id' => $id,
            'name' => $form->input('name'),
            'description' => $form->input('description'),
            'approval_tier' => $form->input('approval_tier'),
            'is_final_authority' => false,
            'is_system' => false,
            'active' => true,
        ]);
        $this->syncPermissions($role, $form->input('permissions', []));
        $this->audit->append('role', $id, 'create', $form->user()->id, [
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => count($form->input('permissions', [])),
        ]);

        return response()->json(['data' => $role->load('permissions')], 201);
    }

    public function update(UpdateRoleRequest $form, Role $role): JsonResponse
    {
        // Immutable in both directions, for system and dynamic roles alike: a
        // dynamic role must not be promotable to org-wide, and a system role must
        // not be demotable. Only a migration may move this flag.
        if ($form->has('is_final_authority') && $form->boolean('is_final_authority') !== $role->is_final_authority) {
            throw ValidationException::withMessages([
                'is_final_authority' => 'The final-authority flag is fixed and can only be changed by a migration.',
            ]);
        }

        $role->update(collect($form->validated())->except(['permissions'])->all());

        if ($form->has('permissions')) {
            $this->syncPermissions($role, $form->input('permissions'));
        }

        // Outside the branch on purpose: every field on this form can change what
        // the role resolves to, so every accepted write must drop the memoised
        // codes. Invalidating only when `permissions` was present left a
        // successful write that silently changed nothing for up to 60s.
        $this->invalidatePermsCache($role);

        $this->audit->append('role', (string) $role->id, 'update', $form->user()->id, [
            'name' => $role->name,
            'description' => $role->description,
        ]);

        return response()->json(['data' => $role->load('permissions')]);
    }

    /**
     * Archive a role.
     *
     * Deletion here means deactivation, not a row delete. The role keeps its id,
     * name and role_permissions, so a decision approved last year still resolves to
     * the role that made it and the audit ledger still reads; what changes is that
     * it stops granting anything to anyone holding it.
     *
     * This is what makes system roles removable at all. The previous behaviour
     * refused to delete FINAL_AUTHORITY, HR or SELF_SERVICE outright, which meant the
     * UI could only ever offer a button the server would reject. Archiving is
     * reversible (POST /roles/{id}/restore) and, because the role grants nothing in
     * the meantime, cannot leave a workflow without an approver by accident - that
     * case is still refused below.
     */
    public function destroy(Request $form, Role $role): JsonResponse
    {
        // Final authority is the terminal approver the resolver falls back to
        // whenever a chain would otherwise resolve to nobody. Archiving it would
        // turn every unresolvable request into one that can never be decided, so
        // it is refused outright rather than offered as a button the server
        // rejects. Only a migration may remove it.
        if ($role->is_final_authority) {
            return response()->json([
                'message' => 'Final Authority is the terminal approver of last resort and cannot be archived.',
                'errors' => ['role' => ['This role cannot be deleted.']],
            ], 422);
        }

        $steps = DB::table('workflow_steps')
            ->join('workflow_definitions', 'workflow_definitions.id', '=', 'workflow_steps.workflow_def_id')
            ->where('workflow_steps.role_required', $role->id)
            ->orderBy('workflow_definitions.id')
            ->orderBy('workflow_steps.step_order')
            ->get([
                'workflow_steps.id',
                'workflow_definitions.id as workflow_def_id',
                'workflow_definitions.leave_type_id',
                'workflow_steps.step_order',
            ]);

        // Archiving a role a live workflow still needs would leave that step with
        // nobody able to act and the request pending forever. Unlike the
        // system-role check this one is a genuine integrity constraint, so it
        // stays a refusal and reports the offending steps — unless the admin
        // hands those steps to another live role in the same request.
        //
        // `reassign_to` is what makes a reorganisation possible at all: an admin
        // consolidating on a single approval role would otherwise have to open
        // every workflow, move every step by hand, and come back for each role.
        if ($steps->isNotEmpty()) {
            $reassignTo = $form->input('reassign_to');

            if (! $reassignTo) {
                return response()->json([
                    'message' => 'Role is still required by '.$steps->count().' workflow step(s). Reassign those steps first.',
                    'errors' => ['role' => ['This role is still required by an approval workflow.']],
                    'blockers' => $steps->map(fn ($step) => [
                        'workflow_def_id' => $step->workflow_def_id,
                        'leave_type_id' => $step->leave_type_id,
                        'step_order' => $step->step_order,
                    ])->all(),
                ], 422);
            }

            $target = Role::find($reassignTo);
            if (! $target || ! $target->active || $target->id === $role->id) {
                return response()->json([
                    'message' => 'The reassignment target must be an active role other than the one being archived.',
                    'errors' => ['reassign_to' => ['Pick a different, active role to take over these approval steps.']],
                ], 422);
            }

            // One write for the whole set: every step the role held now resolves
            // through the target role, so no chain is left pointing at a role
            // that grants nothing.
            DB::table('workflow_steps')
                ->whereIn('id', $steps->pluck('id'))
                ->update(['role_required' => $target->id]);

            $this->audit->append('workflow', 'reassign_steps', 'update', $form->user()->id, [
                'from_role' => $role->id,
                'to_role' => $target->id,
                'steps' => $steps->count(),
                'workflow_defs' => $steps->pluck('workflow_def_id')->unique()->all(),
            ]);
        }

        // Drop cached permission codes while the holders are still resolvable.
        $this->invalidatePermsCache($role);

        $wasSystem = (bool) $role->is_system;
        $name = $role->name;
        $role->update(['active' => false]);

        $this->audit->append('role', (string) $role->id, 'archive', $form->user()->id, [
            'name' => $name,
            'was_system' => $wasSystem,
        ]);

        return response()->json([
            'data' => ['id' => $role->id, 'archived' => true, 'active' => false],
        ], 200);
    }

    /**
     * Undo an archive. The role's permissions are exactly as they were, so
     * restoring is a single flag rather than a reconstruction.
     */
    public function restore(Request $form, Role $role): JsonResponse
    {
        if ($role->active) {
            return response()->json(['data' => ['id' => $role->id, 'active' => true]]);
        }

        $this->invalidatePermsCache($role);

        $name = $role->name;
        $role->update(['active' => true]);

        $this->audit->append('role', (string) $role->id, 'restore', $form->user()->id, [
            'name' => $name,
            'was_system' => (bool) $role->is_system,
        ]);

        return response()->json(['data' => $role->fresh()->load('permissions')]);
    }

    protected function syncPermissions(Role $role, array $codes): void
    {
        // A code that is not enforced cannot be granted. Without this the matrix
        // would store a `planned` code against a role, and the switch would imply
        // a capability that no route or policy consults.
        $ungrantable = Permission::whereIn('code', $codes)
            ->where(fn ($q) => $q->where('active', false)->orWhere('status', '!=', 'implemented'))
            ->pluck('code')
            ->all();

        if ($ungrantable !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['These codes are not enforced yet and cannot be granted: '.implode(', ', $ungrantable)],
            ]);
        }

        $role->permissions()->sync(Permission::whereIn('code', $codes)->pluck('id'));
    }

    /** Permission codes are cached per user; drop them for everyone holding the role. */
    protected function invalidatePermsCache(Role $role): void
    {
        $this->permissions->forgetHoldersOf((string) $role->id);
    }
}
