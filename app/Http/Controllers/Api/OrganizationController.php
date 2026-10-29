<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\PatchDivisionRequest;
use App\Http\Requests\PatchUnitRequest;
use App\Http\Requests\StoreDivisionRequest;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\PositionDefaultRole;
use App\Models\ReportingLine;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use App\Services\AccessScopeService;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    /**
     * Structural roles that only ever exist SCOPED to a department or zone.
     * An unscoped "Department Head" resolves to own-records-only (a NULL/NULL
     * grant is "scope unspecified", not "everywhere"), so minting one through
     * a role list is always a mistake: the head must name a unit, which only
     * the head-appointment endpoints can do.
     */
    private const STRUCTURAL_ROLES = ['DEPT_HEAD', 'ZONAL_HEAD'];

    private const STRUCTURAL_GRANT_MSG = 'Department and zonal heads are appointed to a specific unit. Use the department/zone head picker, not the role list.';

    public function __construct(
        protected AuditService $audit,
        protected PermissionService $permissions,
        protected AccessScopeService $accessScope,
    ) {}

    public function zones(): JsonResponse
    {
        $heads = $this->headGrants('ZONAL_HEAD', 'scope_zone_id');

        return response()->json([
            'data' => Zone::orderBy('name')->get()->map(function (Zone $zone) use ($heads) {
                $zone->setAttribute('head', $this->headPayload($heads->get($zone->id)));

                return $zone;
            }),
        ]);
    }

    public function departments(): JsonResponse
    {
        $heads = $this->headGrants('DEPT_HEAD', 'department_id');

        return response()->json([
            'data' => Department::orderBy('name')->get()->map(function (Department $department) use ($heads) {
                $department->setAttribute('head', $this->headPayload($heads->get($department->id)));

                return $department;
            }),
        ]);
    }

    /** Current head grants keyed by the scope column (one head per unit). */
    protected function headGrants(string $roleId, string $scopeColumn): Collection
    {
        return EmployeeRole::where('role_id', $roleId)
            ->whereNotNull($scopeColumn)
            ->with('employee:id,full_name')
            ->get()
            ->groupBy($scopeColumn)
            ->map(fn ($rows) => $rows->first());
    }

    protected function headPayload(?EmployeeRole $grant): ?array
    {
        if (! $grant?->employee) {
            return null;
        }

        return [
            'employee_id' => $grant->employee_id,
            'full_name' => $grant->employee->full_name,
        ];
    }

    /**
     * Scoped structural grants (department head of d1, zonal head of z4) with
     * their scope, so the UI can show WHICH department/zone a head grant
     * covers instead of a bare "Department Head" label.
     */
    protected function scopedGrants(Employee $employee): array
    {
        return $employee->roles
            ->map(fn (Role $role) => [
                'role_id' => $role->id,
                'department_id' => $role->pivot->department_id,
                'scope_zone_id' => $role->pivot->scope_zone_id,
            ])
            ->filter(fn (array $grant) => $grant['department_id'] !== null || $grant['scope_zone_id'] !== null)
            ->values()
            ->all();
    }

    public function storeZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', 'unique:zones,name'],
            'short' => ['nullable', 'string', 'max:191'],
            'is_hq' => ['sometimes', 'boolean'],
            'is_pemba' => ['sometimes', 'boolean'],
            'is_coordination' => ['sometimes', 'boolean'],
        ]);

        $zone = Zone::create([
            'id' => $this->seqId('zones'),
            'name' => $data['name'],
            'short' => $data['short'] ?? null,
            'is_hq' => (bool) ($data['is_hq'] ?? false),
            'is_pemba' => (bool) ($data['is_pemba'] ?? false),
            'is_coordination' => (bool) ($data['is_coordination'] ?? false),
        ]);

        $this->audit->append('zone', (string) $zone->id, 'create', $request->user()->id, ['name' => $zone->name]);

        return response()->json(['data' => $zone], 201);
    }

    public function updateZone(Request $request, Zone $zone): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191', Rule::unique('zones', 'name')->ignore($zone->id)],
            'short' => ['sometimes', 'nullable', 'string', 'max:191'],
            'is_hq' => ['sometimes', 'boolean'],
            'is_pemba' => ['sometimes', 'boolean'],
            'is_coordination' => ['sometimes', 'boolean'],
        ]);

        $zone->update($data);

        $this->audit->append('zone', (string) $zone->id, 'update', $request->user()->id, [
            'name' => $zone->name,
            'short' => $zone->short,
        ]);

        return response()->json(['data' => $zone->fresh()]);
    }

    public function destroyZone(Request $request, Zone $zone): JsonResponse
    {
        // Zones are referenced by employees and departments. Silently cascading
        // would move staff between zones without an audit trail, so the
        // dependents are reported and the admin reassigns them first.
        $employees = Employee::where('zone_id', $zone->id)->count();
        $departments = Department::where('zone_id', $zone->id)->count();

        if ($employees > 0 || $departments > 0) {
            return response()->json([
                'message' => sprintf(
                    'Zone "%s" still holds %d department(s) and %d employee(s). Move them first.',
                    $zone->name,
                    $departments,
                    $employees,
                ),
                'errors' => ['zone' => ['This zone is still in use.']],
                'blockers' => ['departments' => $departments, 'employees' => $employees],
            ], 422);
        }

        $name = $zone->name;
        $zone->delete();

        $this->audit->append('zone', (string) $zone->id, 'delete', $request->user()->id, ['name' => $name]);

        return response()->json(['data' => ['id' => $zone->id, 'deleted' => true]]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'zone_id' => ['required', 'string', 'exists:zones,id'],
        ]);

        $department = Department::create([
            'id' => $this->seqId('departments'),
            'name' => $data['name'],
            'zone_id' => $data['zone_id'],
        ]);

        $this->audit->append('department', (string) $department->id, 'create', $request->user()->id, [
            'name' => $department->name,
            'zone_id' => $department->zone_id,
        ]);

        return response()->json(['data' => $department], 201);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'zone_id' => ['sometimes', 'string', 'exists:zones,id'],
        ]);

        $department->update($data);

        $this->audit->append('department', (string) $department->id, 'update', $request->user()->id, [
            'name' => $department->name,
            'zone_id' => $department->zone_id,
        ]);

        return response()->json(['data' => $department->fresh()]);
    }

    public function destroyDepartment(Request $request, Department $department): JsonResponse
    {
        $employees = Employee::where('department_id', $department->id)->count();
        $children = Department::where('parent_department_id', $department->id)->count();

        if ($employees > 0 || $children > 0) {
            return response()->json([
                'message' => sprintf(
                    'Department "%s" still holds %d sub-unit(s) and %d employee(s). Move them first.',
                    $department->name,
                    $children,
                    $employees,
                ),
                'errors' => ['department' => ['This department is still in use.']],
                'blockers' => ['sub_units' => $children, 'employees' => $employees],
            ], 422);
        }

        $name = $department->name;
        $department->delete();

        $this->audit->append('department', (string) $department->id, 'delete', $request->user()->id, ['name' => $name]);

        return response()->json(['data' => ['id' => $department->id, 'deleted' => true]]);
    }

    /**
     * Appoint the head of a department. One head per unit: assigning replaces
     * whoever held it. This — not the profile's flat role list — is the only
     * way a scoped structural grant is CREATED; the profile list can drop one
     * but never mints "head of <department>" out of a bare role id.
     */
    public function setDepartmentHead(Request $request, Department $department): JsonResponse
    {
        return $this->setHead($request, 'DEPT_HEAD', 'department_id', (string) $department->id, $department, 'department');
    }

    public function clearDepartmentHead(Request $request, Department $department): JsonResponse
    {
        return $this->clearHead($request, 'DEPT_HEAD', 'department_id', (string) $department->id, $department, 'department');
    }

    public function setZoneHead(Request $request, Zone $zone): JsonResponse
    {
        return $this->setHead($request, 'ZONAL_HEAD', 'scope_zone_id', (string) $zone->id, $zone, 'zone');
    }

    public function clearZoneHead(Request $request, Zone $zone): JsonResponse
    {
        return $this->clearHead($request, 'ZONAL_HEAD', 'scope_zone_id', (string) $zone->id, $zone, 'zone');
    }

    protected function setHead(Request $request, string $roleId, string $scopeColumn, string $scopeId, Department|Zone $unit, string $auditEntity): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', Rule::exists('employees', 'id')],
        ]);

        // Same escalation doctrine as any other grant: the actor must be able
        // to grant the role they are handing out (structural or not).
        $guard = $this->permissions->canGrantRole($request->user(), $roleId);

        if (! $guard['ok']) {
            return response()->json([
                'message' => $guard['reason'],
                'errors' => ['employee_id' => [$guard['reason']]],
            ], 422);
        }

        $employee = Employee::findOrFail($data['employee_id']);
        $previousHolders = EmployeeRole::where('role_id', $roleId)
            ->where($scopeColumn, $scopeId)
            ->pluck('employee_id')
            ->unique()
            ->all();

        DB::transaction(function () use ($roleId, $scopeColumn, $scopeId, $employee, $request) {
            EmployeeRole::where('role_id', $roleId)->where($scopeColumn, $scopeId)->delete();

            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => $roleId,
                $scopeColumn => $scopeId,
                'source' => $roleId === 'DEPT_HEAD' ? 'head_of_department' : 'zonal_head',
                'granted_by' => $request->user()->id,
                'effective_from' => now()->toDateString(),
            ]);
        });

        // Both sides of the swap must resolve codes on the very next request.
        foreach (array_unique([...$previousHolders, $employee->id]) as $holderId) {
            $holder = Employee::find($holderId);
            if ($holder?->user) {
                $this->permissions->forget($holder->user);
            }
        }

        $this->audit->append($auditEntity, $scopeId, 'head_assign', $request->user()->id, [
            'role_id' => $roleId,
            'employee_id' => $employee->id,
            'previous_employee_ids' => $previousHolders,
        ]);

        return response()->json(['data' => $unit->setAttribute('head', $this->headOf($roleId, $scopeColumn, $scopeId))]);
    }

    protected function clearHead(Request $request, string $roleId, string $scopeColumn, string $scopeId, Department|Zone $unit, string $auditEntity): JsonResponse
    {
        $previousHolders = EmployeeRole::where('role_id', $roleId)
            ->where($scopeColumn, $scopeId)
            ->pluck('employee_id')
            ->unique()
            ->all();

        // Idempotent: clearing a unit that has no head is still a 200.
        EmployeeRole::where('role_id', $roleId)->where($scopeColumn, $scopeId)->delete();

        foreach ($previousHolders as $holderId) {
            $holder = Employee::find($holderId);
            if ($holder?->user) {
                $this->permissions->forget($holder->user);
            }
        }

        if ($previousHolders !== []) {
            $this->audit->append($auditEntity, $scopeId, 'head_clear', $request->user()->id, [
                'role_id' => $roleId,
                'previous_employee_ids' => $previousHolders,
            ]);
        }

        return response()->json(['data' => $unit->setAttribute('head', null)]);
    }

    protected function headOf(string $roleId, string $scopeColumn, string $scopeId): ?array
    {
        $grant = EmployeeRole::where('role_id', $roleId)
            ->where($scopeColumn, $scopeId)
            ->with('employee:id,full_name')
            ->first();

        return $this->headPayload($grant);
    }

    public function positions(): JsonResponse
    {
        $positions = Position::with('defaultRoles')->orderBy('title')->get()
            ->map(fn (Position $p) => $this->positionPayload($p));

        return response()->json(['data' => $positions]);
    }

    /**
     * Serialise a position with `default_roles` as a flat id array.
     *
     * The eager-loaded `defaultRoles` relation also serialises to the snake
     * key `default_roles`, and relations overwrite attributes in toArray(),
     * so the relation must be dropped before setting the attribute —
     * otherwise the API returns pivot objects and clients PATCH them back
     * as objects ("The default_role_ids.0 field must be a string.").
     */
    protected function positionPayload(Position $position): Position
    {
        $ids = $position->defaultRoles->pluck('role_id')->all();
        $position->unsetRelation('defaultRoles');

        return $position->setAttribute('default_roles', $ids);
    }

    protected function seqId(string $prefix): string
    {
        $last = (int) DB::table($prefix)->selectRaw('MAX(CAST(SUBSTRING(id,2) AS UNSIGNED)) AS last')->value('last') ?? 0;

        return $prefix[0].($last + 1);
    }

    protected function syncDefaultRoles(string $positionId, ?array $roleIds): void
    {
        PositionDefaultRole::where('position_id', $positionId)->delete();
        foreach (array_unique($roleIds ?? []) as $roleId) {
            PositionDefaultRole::create(['position_id' => $positionId, 'role_id' => $roleId]);
        }
    }

    public function storePosition(StorePositionRequest $form): JsonResponse
    {
        $data = $form->validated();

        if ($bad = array_intersect($data['default_role_ids'] ?? [], self::STRUCTURAL_ROLES)) {
            return response()->json([
                'message' => 'One or more roles cannot be used as defaults.',
                'errors' => ['default_role_ids' => array_values($bad)],
            ], 422);
        }

        $position = Position::create([
            'id' => $this->seqId('positions'),
            'title' => $data['title'],
            'department_id' => $data['department_id'],
            'zone_id' => $data['zone_id'] ?? null,
            'tier' => $data['tier'] ?? 1,
            'is_final_authority' => $data['is_final_authority'] ?? false,
        ]);
        $this->syncDefaultRoles($position->id, $data['default_role_ids'] ?? null);
        $this->audit->append('position', (string) $position->id, 'create', $form->user()->id, [
            'title' => $position->title,
            'department_id' => $position->department_id,
        ]);

        return response()->json(['data' => $this->positionPayload($position->load('defaultRoles'))], 201);
    }

    public function updatePosition(UpdatePositionRequest $form, Position $position): JsonResponse
    {
        $data = $form->validated();

        if ($bad = array_intersect($data['default_role_ids'] ?? [], self::STRUCTURAL_ROLES)) {
            return response()->json([
                'message' => 'One or more roles cannot be used as defaults.',
                'errors' => ['default_role_ids' => array_values($bad)],
            ], 422);
        }

        $position->update(collect($data)->forget('default_role_ids')->all());
        if (array_key_exists('default_role_ids', $data)) {
            $this->syncDefaultRoles($position->id, $data['default_role_ids']);
        }
        $this->audit->append('position', (string) $position->id, 'update', $form->user()->id, [
            'title' => $position->title,
        ]);

        return response()->json(['data' => $this->positionPayload($position->load('defaultRoles'))]);
    }

    public function destroyPosition(Position $position): JsonResponse
    {
        if (Employee::where('position_id', $position->id)->exists()) {
            return response()->json(['message' => 'Position is assigned to staff and cannot be deleted.'], 422);
        }
        $id = $position->id;
        $title = $position->title;
        PositionDefaultRole::where('position_id', $id)->delete();
        $position->delete();
        $this->audit->append('position', $id, 'delete', request()->user()->id, ['title' => $title]);

        return response()->json(['message' => 'Position deleted.']);
    }

    /**
     * Permanent staff deletion — a hard remove, next to activate/deactivate.
     *
     * The guards protect three invariants, in order:
     *   1. an administrator cannot delete their own record;
     *   2. head appointments are cleared explicitly (head doctrine: a unit
     *      never silently loses its head through a directory delete);
     *   3. anything that participates in history — own leave requests,
     *      approval actions on OTHER people's requests, handover records,
     *      assigned cases — is refused with "deactivate instead", because
     *      those foreign keys are RESTRICT and the audit trail must keep
     *      naming real people.
     *
     * Everything else cascades: role grants, reporting lines, balances,
     * delegations, notifications, plans — and the linked user account is
     * removed too so the login cannot outlive the employee.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        if ($employee->id === $request->user()->employee?->id) {
            return response()->json(['message' => 'You cannot delete your own staff record — ask another administrator.'], 422);
        }

        $headsHead = EmployeeRole::where('employee_id', $employee->id)
            ->whereIn('role_id', self::STRUCTURAL_ROLES)
            ->where(fn ($q) => $q->whereNotNull('department_id')->orWhereNotNull('scope_zone_id'))
            ->exists();
        if ($headsHead) {
            return response()->json(['message' => 'They head a department or zone — clear the head appointment first.'], 422);
        }

        $ownRequests = LeaveRequest::where('employee_id', $employee->id)->count();
        if ($ownRequests > 0) {
            return response()->json(['message' => "They have {$ownRequests} leave request(s) — history is permanent. Deactivate the record instead."], 422);
        }

        $inHistory = DB::table('workflow_assignments')->where('assigned_employee_id', $employee->id)->exists()
            || DB::table('approval_actions')->where('actor_employee_id', $employee->id)->exists()
            || DB::table('case_handovers')
                ->where(fn ($q) => $q->where('from_employee_id', $employee->id)->orWhere('to_employee_id', $employee->id))
                ->exists()
            || DB::table('cases')->where('assigned_prosecutor_id', $employee->id)->exists();
        if ($inHistory) {
            return response()->json(['message' => 'They appear in approval or handover history — history is permanent. Deactivate the record instead.'], 422);
        }

        $userId = $employee->user_id;
        DB::transaction(function () use ($request, $employee, $userId) {
            $this->audit->append('employee', (string) $employee->id, 'delete', $request->user()->id, [
                'full_name' => $employee->full_name,
                'payroll_no' => $employee->payroll_no,
                'position_id' => $employee->position_id,
                'department_id' => $employee->department_id,
                'zone_id' => $employee->zone_id,
            ]);

            // Cascades: employee_roles, reporting_lines, leave_balances,
            // delegations, notifications, waivers, plans, balance
            // transactions, leave_returns, leave_payment_cases.
            $employee->delete();

            // The login must not outlive the employee. Audit/document FKs
            // referencing this user are nullOnDelete; notification
            // preferences cascade.
            if ($userId) {
                User::where('id', $userId)->delete();
            }
        });

        return response()->json(['message' => 'Staff member deleted.']);
    }

    /**
     * Staff directory.
     *
     * Zone/department scoped via AccessScopeService: a regional office sees
     * only staff in its own zone/department, while holders of
     * staff.view_cross_zone (HR, FINAL_AUTHORITY) see the whole organisation.
     * Sensitive columns stay hidden unless the caller also holds admin.view.
     */
    public function employees(Request $request): JsonResponse
    {
        $canSeeSensitive = $this->permissions->can($request->user(), 'admin', 'view');

        $query = Employee::with(['department', 'zone', 'position', 'roles'])
            ->when($request->input('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->input('active'), fn ($q, $a) => $q->where('is_active', true));

        $this->accessScope->applyEmployeeScope($query, $request->user());

        return response()->json([
            'data' => $query->get()
                ->map(function (Employee $e) use ($canSeeSensitive) {
                    $e->setAttribute('role_ids', $e->roles->pluck('id')->unique()->values()->all());
                    // Callers that want to change assignments must send only these
                    // back; the rest of role_ids are scoped structural grants.
                    $e->setAttribute('unscoped_role_ids', $e->unscopedRoleIds());
                    $e->setAttribute('scoped_grants', $this->scopedGrants($e));

                    if (! $canSeeSensitive) {
                        $e->makeHidden(['payroll_no', 'gender']);
                    }

                    return $e;
                })
                ->sortBy('full_name')
                ->values(),
        ]);
    }

    /** Patch staff/profile fields (frontend settings updateEmployee). */
    public function update(UpdateEmployeeRequest $form, Employee $employee): JsonResponse
    {
        $data = $form->validated();
        $employee->update(collect($data)->forget(['role_ids', 'password'])->all());

        if (array_key_exists('password', $data) && ! empty($data['password'])) {
            $employee->user?->update(['password' => $data['password']]);
        }

        if (array_key_exists('role_ids', $data)) {
            $roleIds = array_values(array_unique($data['role_ids'] ?? []));

            // Scoped rows are structural (department head of d1, zonal head of
            // z4). Capture them first: a caller that resubmits the flat role
            // list (which includes scoped role IDs) must not have them
            // re-materialised as unscoped, or "head of d1" silently becomes
            // "head of every department".
            //
            // The flip side: the flat list is authoritative for REMOVAL too.
            // The edit dialog shows scoped grants in the same picker, so a
            // scoped role left out of the payload was deliberately unchecked
            // and is dropped below. Scoped rows are never CREATED here, only
            // kept or removed — which is what makes "remove Department Head
            // from the profile" actually stick.
            $scopedRoleIds = array_values(array_diff(
                $employee->roles->pluck('id')->unique()->values()->all(),
                $employee->unscopedRoleIds()
            ));

            // Validate BEFORE deleting anything. This path replaces the whole
            // unscoped grant set, so checking inside the write loop would leave
            // the employee stripped of every role if the last id was rejected.
            //
            // Only newly-added ids are checked: re-saving an assignment the
            // employee already holds (e.g. the DPP's FINAL_AUTHORITY) is not a
            // grant, and must not trip the escalation guard — which would make
            // the profile unsavable. New ids still face canGrantRole(), so
            // first-time FINAL_AUTHORITY (and other) grants stay refused.
            $incoming = array_values(array_diff($roleIds, $scopedRoleIds));

            // A structural id that survives the scoped strip is an unscoped
            // head (legacy drift, or the picker offering it as addable).
            // Refusing it here blocks BOTH minting a new one and re-creating
            // one that was held unscoped before this rule existed; unchecking
            // the role (omitting the id) still removes it via the whereNotIn
            // delete below, so the drift is cleanable through the profile.
            if (array_intersect($incoming, self::STRUCTURAL_ROLES) !== []) {
                return response()->json([
                    'message' => 'One or more roles cannot be granted by you.',
                    'errors' => ['role_ids' => array_values(array_intersect($incoming, self::STRUCTURAL_ROLES))],
                ], 422);
            }

            $newRoles = array_values(array_diff($incoming, $employee->unscopedRoleIds()));
            $refused = [];

            foreach ($newRoles as $roleId) {
                $guard = $this->permissions->canGrantRole($form->user(), $roleId);

                if (! $guard['ok']) {
                    $refused[$roleId] = $guard['reason'];
                }
            }

            if ($refused !== []) {
                return response()->json([
                    'message' => 'One or more roles cannot be granted by you.',
                    'errors' => ['role_ids' => array_values($refused)],
                ], 422);
            }

            // FINAL_AUTHORITY is migration-appointed in both directions: the
            // guard above blocks adding it, and this blocks dropping it —
            // which was reachable simply by omitting the id from the payload.
            $dropped = array_values(array_diff($employee->unscopedRoleIds(), $incoming));
            foreach ($dropped as $roleId) {
                if (Role::find($roleId)?->is_final_authority) {
                    return response()->json([
                        'message' => 'One or more roles cannot be granted by you.',
                        'errors' => ['role_ids' => ['Final authority cannot be removed through the API. It is managed by migration only.']],
                    ], 422);
                }
            }

            // Anything the flat list no longer carries is gone — scoped
            // department/zone grants included (an empty role_ids array strips
            // every grant; an empty whereNotIn matches all rows).
            EmployeeRole::where('employee_id', $employee->id)
                ->whereNotIn('role_id', $roleIds)
                ->delete();

            // Replace only the unconditional (unscoped) approvals.
            EmployeeRole::where('employee_id', $employee->id)
                ->whereNull('department_id')
                ->whereNull('scope_zone_id')
                ->delete();

            foreach ($incoming as $roleId) {
                // A break-glass grant is never open-ended: it is written with the
                // clamped expiry so it lapses on its own rather than depending on
                // someone remembering to revoke it.
                $isBreakGlass = in_array($roleId, $this->permissions->breakGlassIn([$roleId]), true);

                EmployeeRole::create([
                    'employee_id' => $employee->id,
                    'role_id' => $roleId,
                    'source' => $isBreakGlass ? 'break_glass' : 'manual_grant',
                    'granted_by' => $form->user()->id,
                    'effective_from' => now()->toDateString(),
                    'effective_to' => $isBreakGlass ? $this->permissions->breakGlassExpiry() : null,
                ]);

                if ($isBreakGlass) {
                    $this->audit->append('employee_role', $roleId, 'break_glass_grant', $form->user()->id, [
                        'employee_id' => $employee->id,
                        'expires_on' => $this->permissions->breakGlassExpiry(),
                    ]);
                }
            }

            // Grants changed: the new codes must resolve on the very next request.
            $this->permissions->forget($employee->user);
        }

        $this->audit->append('employee', (string) $employee->id, 'update', $form->user()->id, [
            'full_name' => $employee->full_name,
        ]);

        return response()->json(['data' => $employee->load(['department', 'zone', 'position', 'roles'])
            ->setAttribute('role_ids', $employee->roles->pluck('id')->unique()->values()->all())
            ->setAttribute('unscoped_role_ids', $employee->unscopedRoleIds())
            ->setAttribute('scoped_grants', $this->scopedGrants($employee))]);
    }

    /**
     * Grant ONE unscoped role to an employee (frontend assignCustomRole).
     *
     * Deliberately not a full role_ids replacement: that path rewrites every
     * unscoped grant and would also materialise an unscoped duplicate of a
     * structural department/zonal grant (turning "head of d1" into "head of
     * every department"). This touches exactly one row and leaves scoped
     * grants alone.
     */
    public function grantRole(Request $form, Employee $employee, string $role): JsonResponse
    {
        // Archived roles are still rows, so a bare exists() would let one be
        // re-added after an admin retired it. The grant resolves to nothing
        // (activeAssignments filters role.active) but it reappears in the matrix
        // as if it were live configuration.
        // canGrantRole() covers existence, the archived flag and the escalation check
        // (a final-authority role is never grantable, and no role may carry codes
        // the caller does not hold). Without the last one, admin.update alone was
        // enough to mint a 23-permission organisation-wide account.
        $guard = $this->permissions->canGrantRole($form->user(), $role);

        if (! $guard['ok']) {
            return response()->json([
                'message' => $guard['reason'],
                'errors' => ['role_id' => [$guard['reason']]],
            ], 422);
        }

        // This endpoint only ever mints unscoped rows, so a structural role
        // granted here would be "head of nowhere" (own-records-only scope).
        if (in_array($role, self::STRUCTURAL_ROLES, true)) {
            return response()->json([
                'message' => self::STRUCTURAL_GRANT_MSG,
                'errors' => ['role_id' => [self::STRUCTURAL_GRANT_MSG]],
            ], 422);
        }

        $already = EmployeeRole::where('employee_id', $employee->id)
            ->where('role_id', $role)
            ->whereNull('department_id')
            ->whereNull('scope_zone_id')
            ->exists();

        if (! $already) {
            $isBreakGlass = in_array($role, $this->permissions->breakGlassIn([$role]), true);

            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => $role,
                'source' => $isBreakGlass ? 'break_glass' : 'manual_grant',
                'granted_by' => $form->user()->id,
                'effective_from' => now()->toDateString(),
                'effective_to' => $isBreakGlass ? $this->permissions->breakGlassExpiry() : null,
            ]);
        }

        $this->permissions->forget($employee->user);

        $this->audit->append('employee_role', $role, $already ? 'grant_noop' : 'grant', $form->user()->id, [
            'employee_id' => $employee->id,
            'already_held' => $already,
            'expires_on' => $this->permissions->breakGlassIn([$role]) !== [] ? $this->permissions->breakGlassExpiry() : null,
        ]);

        return $this->roleGrantsPayload($employee);
    }

    /**
     * Revoke ONE unscoped role grant. Scoped (department/zonal) grants are
     * structural and are refused here rather than silently widened or removed.
     */
    public function revokeRole(Request $form, Employee $employee, string $role): JsonResponse
    {
        $scoped = EmployeeRole::where('employee_id', $employee->id)
            ->where('role_id', $role)
            ->where(function ($q) {
                $q->whereNotNull('department_id')->orWhereNotNull('scope_zone_id');
            })
            ->exists();

        if ($scoped) {
            return response()->json([
                'message' => 'This role is held through a scoped grant and cannot be revoked here.',
                'errors' => ['role_id' => ['Scoped (department/zone) grants are managed on the employee record.']],
            ], 422);
        }

        $removed = EmployeeRole::where('employee_id', $employee->id)
            ->where('role_id', $role)
            ->whereNull('department_id')
            ->whereNull('scope_zone_id')
            ->delete();

        if ($removed === 0) {
            return response()->json([
                'message' => 'Role is not held unscoped by this employee.',
                'errors' => ['role_id' => ['Nothing to revoke.']],
            ], 422);
        }

        $this->permissions->forget($employee->user);

        $this->audit->append('employee_role', $role, 'revoke', $form->user()->id, [
            'employee_id' => $employee->id,
        ]);

        return $this->roleGrantsPayload($employee);
    }

    /** Employee row plus the unscoped/scoped split the matrix UI needs. */
    protected function roleGrantsPayload(Employee $employee): JsonResponse
    {
        $employee->load(['roles']);

        return response()->json(['data' => $employee
            ->setAttribute('role_ids', $employee->roles->pluck('id')->unique()->values()->all())
            ->setAttribute('unscoped_role_ids', EmployeeRole::where('employee_id', $employee->id)
                ->whereNull('department_id')
                ->whereNull('scope_zone_id')
                ->pluck('role_id')
                ->all())
            ->setAttribute('scoped_grants', $this->scopedGrants($employee))]);
    }

    /**
     * Register a new staff member from the admin panel: creates the login,
     * the employee record, default roles (position defaults unless role_ids
     * given) and the primary reporting line to a supervisor.
     */
    public function store(StoreEmployeeRequest $form): JsonResponse
    {
        $data = $form->validated();

        // Escalation check before anything is written. Validating after the
        // User and Employee rows existed would leave an orphaned half-created
        // account behind on every rejected request.
        //
        // Only explicit role_ids are checked. Position defaults are
        // org-configured rather than caller-chosen, so they are not an
        // escalation path; an admin who sets those defaults is governed by the
        // same admin.create gate as editing any other configuration.
        if (array_key_exists('role_ids', $data)) {
            $refused = [];

            foreach (array_unique($data['role_ids'] ?? []) as $roleId) {
                // Registration mints unscoped rows only; a structural role
                // here would be an unscoped head (own-records-only). Heads are
                // appointed per unit after the fact, through the head picker.
                if (in_array($roleId, self::STRUCTURAL_ROLES, true)) {
                    $refused[$roleId] = self::STRUCTURAL_GRANT_MSG;

                    continue;
                }

                $guard = $this->permissions->canGrantRole($form->user(), $roleId);

                if (! $guard['ok']) {
                    $refused[$roleId] = $guard['reason'];
                }
            }

            if ($refused !== []) {
                return response()->json([
                    'message' => 'One or more roles cannot be granted by you.',
                    'errors' => ['role_ids' => array_values($refused)],
                ], 422);
            }
        }

        $userSeq = (int) (User::selectRaw('MAX(CAST(SUBSTRING(id,2) AS UNSIGNED)) AS last')->value('last') ?? 0);
        $empSeq = (int) (Employee::selectRaw('MAX(CAST(SUBSTRING(id,2) AS UNSIGNED)) AS last')->value('last') ?? 0);
        $userId = 'u'.($userSeq + 1);
        $empId = 'e'.($empSeq + 1);

        $user = User::create([
            'id' => $userId,
            'username' => $data['username'] ?? $data['email'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $employee = Employee::create([
            'id' => $empId,
            'user_id' => $userId,
            'payroll_no' => $data['payroll_no'] ?? null,
            'full_name' => $data['full_name'],
            'initials' => $data['initials'] ?? null,
            'position_id' => $data['position_id'],
            'department_id' => $data['department_id'],
            'zone_id' => $data['zone_id'],
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'hire_date' => $data['hire_date'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $roleIds = $data['role_ids'] ?? PositionDefaultRole::where('position_id', $data['position_id'])->pluck('role_id')->all();
        foreach ($roleIds as $roleId) {
            // Position defaults are org-configured, but a structural role in
            // them still cannot be minted unscoped here (it would resolve to
            // own-records-only); the head is appointed per unit afterwards.
            if (in_array($roleId, self::STRUCTURAL_ROLES, true)) {
                continue;
            }

            // Same clamp as update() and grantRole(). Creating a staff member with
            // role_ids:['BREAK_GLASS'] used to write effective_to => null, which is
            // the one path that could mint an open-ended 23-permission grant: the
            // safety valve that is supposed to be temporary became permanent.
            $isBreakGlass = in_array($roleId, $this->permissions->breakGlassIn([$roleId]), true);

            EmployeeRole::create([
                'employee_id' => $empId,
                'role_id' => $roleId,
                'source' => ($data['role_ids'] ?? null) === null ? 'position_default' : 'manual_grant',
                'granted_by' => $form->user()->id,
                'effective_from' => now()->toDateString(),
                'effective_to' => $isBreakGlass ? $this->permissions->breakGlassExpiry() : null,
            ]);

            if ($isBreakGlass) {
                $this->audit->append('employee_role', $roleId, 'break_glass_grant', $form->user()->id, [
                    'employee_id' => $empId,
                    'expires_on' => $this->permissions->breakGlassExpiry(),
                ]);
            }
        }

        if (! empty($data['supervisor_employee_id'])) {
            ReportingLine::create([
                'employee_id' => $empId,
                'supervisor_employee_id' => $data['supervisor_employee_id'],
                'relationship_type' => 'primary',
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
            ]);
        }

        $this->audit->append('employee', $empId, 'create', $form->user()->id, [
            'full_name' => $employee->full_name,
            'department_id' => $employee->department_id,
            'roles' => count($roleIds),
        ]);

        return response()->json(['data' => $employee->load(['department', 'zone', 'position', 'roles'])
            ->setAttribute('role_ids', $employee->roles->pluck('id')->unique()->values()->all())
            ->setAttribute('scoped_grants', $this->scopedGrants($employee))], 201);
    }

    /** Department hierarchy tree (blueprint (2) lineage). */
    public function orgTree(): JsonResponse
    {
        $tree = Department::all()->sortBy('name')->values()
            ->filter(fn (Department $d) => $d->parent_department_id === null)
            ->map(fn (Department $root) => $this->branch($root));

        return response()->json(['data' => $tree->values()]);
    }

    protected function branch(Department $root): array
    {
        return [
            'id' => $root->id,
            'name' => $root->name,
            'unit_type' => $root->unit_type,
            'reports_directly_to_leadership' => $root->reports_directly_to_leadership,
            'children' => $root->children
                ->sortBy('name')
                ->values()
                ->map(fn (Department $child) => $this->branch($child))
                ->all(),
        ];
    }

    // ===================== Divisions CRUD =====================

    public function indexDivisions(Request $request): JsonResponse
    {
        $query = Department::where('unit_type', 'division');

        // Filter by parent department
        $parentId = $request->input('parent_department_id');
        if ($parentId) {
            $query->where('parent_department_id', $parentId);
        }

        // Filter by zone
        $zoneId = $request->input('zone_id');
        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }

        // Filter by status
        $isActive = $request->input('is_active');
        if ($isActive !== null) {
            $query->where('is_active', $isActive !== 'false');
        }

        $divisions = $query->with(['zone', 'parent', 'headGrant'])
            ->latest('sort_order')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => collect($divisions->items())->map(function (Department $d) use ($request) {
                $head = $d->headGrant ?? null;
                $employeeCount = Employee::where('department_id', $d->id)->where('is_active', true)->count();

                return [
                    'id' => $d->id,
                    'code' => $d->code ?? '—',
                    'name' => $d->name,
                    'name_sw' => $d->name_sw,
                    'zone' => $d->zone?->name,
                    'parent_department' => $d->parent?->name,
                    'head' => $head ? [
                        'employee_id' => $head->employee_id,
                        'full_name' => $head->employee?->full_name,
                    ] : null,
                    'active_employees_count' => $employeeCount,
                    'is_active' => $d->is_active,
                    'sort_order' => $d->sort_order,
                ];
            }),
            'pagination' => [
                'total' => $divisions->total(),
                'per_page' => $divisions->perPage(),
                'current_page' => $divisions->currentPage(),
                'last_page' => $divisions->lastPage(),
                'path' => $divisions->url(),
            ],
            'filters_applied' => [
                'parent_department_id' => $parentId,
                'zone_id' => $zoneId,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function storeDivision(StoreDivisionRequest $request): JsonResponse
{
    $data = $request->validated();

    // Auto-generate a unique `code` when the client omits it.
    // (FormRequest still allows 'nullable' for code; this fills it in.)
    if (empty($data['code'])) {
        $base = strtolower(\Str::slug($data['name'] ?? 'division', '_'));
        $code = $base;
        $i = 1;
        while (Department::where('code', $code)->exists()) {
            $code = $base . '_' . $i++;
        }
        $data['code'] = $code;
    }

    // Validate parent department is not a circular parent
    $parentId = $data['parent_department_id'] ?? null;
    if ($parentId) {
        $child = Department::find($data['id'] ?? $this->seqId('departments'));
        if (!Department::validateNoCircularParent($child, Department::find($parentId))) {
            return response()->json([
                'message' => 'Circular hierarchy detected: a division cannot be its own ancestor.',
                'errors' => ['parent_department_id' => ['Circular hierarchy detected.']],
            ], 422);
        }
    }

    $division = Department::create([
        'id'                    => $this->seqId('departments'),
        'name'                  => $data['name'],
        'code'                  => $data['code'],
        'name_sw'               => $data['name_sw'] ?? null,
        'zone_id'               => $data['zone_id'] ?? null,
        'parent_department_id'  => $parentId,
        'unit_type'             => 'division',
        'is_active'             => $data['is_active'] ?? true,
        'sort_order'            => $data['sort_order'] ?? 0,
        'effective_from'        => $data['effective_from'] ?? null,
        'effective_to'          => $data['effective_to'] ?? null,
    ]);

    $this->audit->append('department', (string) $division->id, 'create', $request->user()->id, [
        'name' => $division->name,
        'unit_type' => 'division',
        'parent_department_id' => $parentId,
    ]);

    return response()->json(['data' => $this->divisionToArray($division)], 201);
}

    public function showDivision(Department $division): JsonResponse
    {
        $division->load(['zone', 'parent', 'headGrant', 'children', 'employees' => function ($q) {
            $q->where('is_active', true)->with('position', 'zone', 'department');
        }]);

        $employeeCount = $division->employees->count();
        $activeEmployeeCount = $division->employees->where('is_active', true)->count();

        $head = $division->headGrant ?? null;

        return response()->json([
            'data' => $this->divisionDetailToArray($division, $head, $activeEmployeeCount),
            'employees' => $division->employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'payroll_no' => $e->payroll_no,
                'position' => $e->position?->title,
                'zone' => $e->zone?->name,
                'department' => $e->department?->name,
                'email' => $e->email,
                'is_active' => $e->is_active,
            ]),
        ]);
    }

    public function updateDivision(PatchDivisionRequest $request, Department $division): JsonResponse
    {
        $data = $request->validated();

        // Validate circular parent if parent is being changed
        if (array_key_exists('parent_department_id', $data) && $data['parent_department_id']) {
            $parentDept = Department::find($data['parent_department_id']);
            if (!$parentDept) {
                return response()->json([
                    'message' => 'Parent department not found.',
                    'errors' => ['parent_department_id' => ['Parent department not found.']],
                ], 422);
            }
            // Check that division is not its own ancestor
            $child = $division->is_pending ? $division : Department::find($division->id);
            if (!Department::validateNoCircularParent($child, $parentDept)) {
                return response()->json([
                    'message' => 'Circular hierarchy detected: a division cannot be its own ancestor.',
                    'errors' => ['parent_department_id' => ['Circular hierarchy detected.']],
                ], 422);
            }
        }

        // Build audit data
        $auditData = [];
        if (array_key_exists('name', $data)) {
            $auditData['name'] = $data['name'];
        }
        if (array_key_exists('code', $data)) {
            $auditData['code'] = $data['code'];
        }
        if (array_key_exists('parent_department_id', $data)) {
            $auditData['parent_department_id'] = $data['parent_department_id'];
        }
        if (array_key_exists('zone_id', $data)) {
            $auditData['zone_id'] = $data['zone_id'];
        }
        if (array_key_exists('is_active', $data)) {
            $auditData['is_active'] = $data['is_active'];
        }

        $division->update($data);

        $this->audit->append('department', (string) $division->id, 'update', $request->user()->id, $auditData);

        return response()->json(['data' => $this->divisionToArray($division)]);
    }

    public function destroyDivision(Request $request, Department $division): JsonResponse
    {
        // Check for active references before archive
        $hasEmployees = Employee::where('department_id', $division->id)->where('is_active', true)->exists();
        $hasChildren = Department::where('parent_department_id', $division->id)->where('is_active', true)->exists();

        if ($hasEmployees || $hasChildren) {
            $blockers = [];
            if ($hasEmployees) {
                $employeeCount = Employee::where('department_id', $division->id)->where('is_active', true)->count();
                $blockers[] = ["employees" => $employeeCount];
            }
            if ($hasChildren) {
                $childCount = Department::where('parent_department_id', $division->id)->where('is_active', true)->count();
                $blockers[] = ["sub_units" => $childCount];
            }

            return response()->json([
                'message' => sprintf(
                    'Division "%s" still holds %d active employee(s) and/or %d active sub-unit(s). Move them first.',
                    $division->name,
                    ...array_values(array_combine(['employees', 'sub_units'], $blockers ?? [])),
                ),
                'errors' => ['division' => ['This division is still in use.']],
                'blockers' => $blockers,
            ], 422);
        }

        $name = $division->name;
        $division->update(['is_active' => false]);

        $this->audit->append('department', (string) $division->id, 'archive', $request->user()->id, [
            'name' => $name,
            'archived' => true,
        ]);

        return response()->json(['data' => ['id' => $division->id, 'archived' => true, 'name' => $name]]);
    }

    public function assignDivisionHead(Request $request, Department $division): JsonResponse
    {
        return $this->setHead($request, 'DEPT_HEAD', 'department_id', (string) $division->id, $division, 'division');
    }

    public function getDivisionHead(Department $division): JsonResponse
    {
        $head = $this->headOf('DEPT_HEAD', 'department_id', (string) $division->id);
        return response()->json(['data' => $head]);
    }

    public function getDivisionEmployees(Request $request, Department $division): JsonResponse
    {
        $query = Employee::where('department_id', $division->id)->with(['user', 'position', 'zone', 'department']);

        // Apply scope
        $canSeeSensitive = $this->permissions->can($request->user(), 'admin', 'view');
        if (! $canSeeSensitive) {
            $query->where(function ($q) {
                $q->where('gender', null)->where('payroll_no', null);
            });
        }

        $employees = $query->when($request->input('active'), fn ($q, $a) => $q->where('is_active', true))
            ->latest('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => collect($employees->items())
                ->map(function (Employee $e) use ($canSeeSensitive) {
                    $e->hidden = ! $canSeeSensitive ? ['payroll_no', 'gender'] : [];
                    return $e;
                })
                ->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'payroll_no' => $canSeeSensitive ? $e->payroll_no : null,
                'gender' => $canSeeSensitive ? $e->gender : null,
                'position' => $e->position?->title,
                'zone' => $e->zone?->name,
                'department' => $e->department?->name,
                'email' => $e->email,
                'is_active' => $e->is_active,
                'hire_date' => $e->hire_date?->format('Y-m-d'),
            ]),
            'pagination' => [
                'total' => $employees->total(),
                'per_page' => $employees->perPage(),
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
            ],
        ]);
    }

    // ===================== Units (Independent Units) CRUD =====================

    public function indexUnits(Request $request): JsonResponse
    {
        $query = Department::where('unit_type', 'independent_unit');

        // Filter by zone
        $zoneId = $request->input('zone_id');
        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }

        // Filter by status
        $isActive = $request->input('is_active');
        if ($isActive !== null) {
            $query->where('is_active', $isActive !== 'false');
        }

        $units = $query->with(['zone', 'parent', 'headGrant'])
            ->latest('sort_order')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => collect($units->items())->map(function (Department $d) use ($request) {
                $head = $d->headGrant ?? null;
                $employeeCount = Employee::where('department_id', $d->id)->where('is_active', true)->count();

                return [
                    'id' => $d->id,
                    'code' => $d->code ?? '—',
                    'name' => $d->name,
                    'name_sw' => $d->name_sw,
                    'unit_type' => $d->unit_type,
                    'reports_to' => $d->parent?->name ?? '—',
                    'zone' => $d->zone?->name,
                    'head' => $head ? [
                        'employee_id' => $head->employee_id,
                        'full_name' => $head->employee?->full_name,
                    ] : null,
                    'active_employees_count' => $employeeCount,
                    'is_active' => $d->is_active,
                    'sort_order' => $d->sort_order,
                ];
            }),
            'pagination' => [
                'total' => $units->total(),
                'per_page' => $units->perPage(),
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'path' => $units->url(),
            ],
            'filters_applied' => [
                'zone_id' => $zoneId,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function storeUnit(StoreUnitRequest $request): JsonResponse
{
    $data = $request->validated();

    // Auto-generate a unique `code` when the client omits it.
    if (empty($data['code'])) {
        $base = strtolower(\Str::slug($data['name'] ?? 'unit', '_'));
        $code = $base;
        $i = 1;
        while (Department::where('code', $code)->exists()) {
            $code = $base . '_' . $i++;
        }
        $data['code'] = $code;
    }

    // Validate circular hierarchy
    $parentId = $data['parent_department_id'] ?? null;
    if ($parentId) {
        $child = Department::find($this->seqId('departments'));
        if (!Department::validateNoCircularParent($child, Department::find($parentId))) {
            return response()->json([
                'message' => 'Circular hierarchy detected.',
                'errors' => ['parent_department_id' => ['Circular hierarchy detected.']],
            ], 422);
        }
    }

    $unit = Department::create([
        'id'                    => $this->seqId('departments'),
        'name'                  => $data['name'],
        'code'                  => $data['code'],
        'name_sw'               => $data['name_sw'] ?? null,
        'zone_id'               => $data['zone_id'] ?? null,
        'parent_department_id'  => $parentId,
        'unit_type'             => 'independent_unit',
        'is_active'             => $data['is_active'] ?? true,
        'sort_order'            => $data['sort_order'] ?? 0,
        'effective_from'        => $data['effective_from'] ?? null,
        'effective_to'          => $data['effective_to'] ?? null,
    ]);

    $this->audit->append('department', (string) $unit->id, 'create', $request->user()->id, [
        'name' => $unit->name,
        'unit_type' => 'independent_unit',
        'parent_department_id' => $parentId,
    ]);

    return response()->json(['data' => $this->unitToArray($unit)], 201);
}

    public function showUnit(Department $unit): JsonResponse
    {
        $unit->load(['zone', 'parent', 'headGrant', 'children', 'employees' => function ($q) {
            $q->where('is_active', true)->with('position', 'zone', 'department');
        }]);

        $employeeCount = $unit->employees->count();
        $activeEmployeeCount = $unit->employees->where('is_active', true)->count();

        $head = $unit->headGrant ?? null;

        return response()->json([
            'data' => $this->unitDetailToArray($unit, $head, $activeEmployeeCount),
            'employees' => $unit->employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'payroll_no' => $e->payroll_no,
                'position' => $e->position?->title,
                'zone' => $e->zone?->name,
                'department' => $e->department?->name,
                'email' => $e->email,
                'is_active' => $e->is_active,
                'hire_date' => $e->hire_date?->format('Y-m-d'),
            ]),
        ]);
    }

    public function updateUnit(PatchUnitRequest $request, Department $unit): JsonResponse
    {
        $data = $request->validated();

        // Validate circular parent if parent is being changed
        if (array_key_exists('parent_department_id', $data) && $data['parent_department_id']) {
            $parentDept = Department::find($data['parent_department_id']);
            if (!$parentDept) {
                return response()->json([
                    'message' => 'Parent department not found.',
                    'errors' => ['parent_department_id' => ['Parent department not found.']],
                ], 422);
            }
            if (!Department::validateNoCircularParent($unit, $parentDept)) {
                return response()->json([
                    'message' => 'Circular hierarchy detected: a unit cannot be its own ancestor.',
                    'errors' => ['parent_department_id' => ['Circular hierarchy detected.']],
                ], 422);
            }
        }

        $unit->update($data);

        $this->audit->append('department', (string) $unit->id, 'update', $request->user()->id, [
            'name' => $unit->name,
        ]);

        return response()->json(['data' => $this->unitToArray($unit)]);
    }

    public function destroyUnit(Request $request, Department $unit): JsonResponse
    {
        // Check for active references before archive
        $hasEmployees = Employee::where('department_id', $unit->id)->where('is_active', true)->exists();
        $hasChildren = Department::where('parent_department_id', $unit->id)->where('unit_type', 'independent_unit')
            ->where('is_active', true)->exists();

        if ($hasEmployees || $hasChildren) {
            $blockers = [];
            if ($hasEmployees) {
                $employeeCount = Employee::where('department_id', $unit->id)->where('is_active', true)->count();
                $blockers[] = ["employees" => $employeeCount];
            }
            if ($hasChildren) {
                $childCount = Department::where('parent_department_id', $unit->id)
                    ->where('unit_type', 'independent_unit')
                    ->where('is_active', true)
                    ->count();
                $blockers[] = ["sub_units" => $childCount];
            }

            return response()->json([
                'message' => sprintf(
                    'Unit "%s" still holds %d active employee(s) and/or %d active sub-unit(s). Move them first.',
                    $unit->name,
                    ...array_values(array_combine(['employees', 'sub_units'], $blockers ?? [])),
                ),
                'errors' => ['unit' => ['This unit is still in use.']],
                'blockers' => $blockers,
            ], 422);
        }

        $name = $unit->name;
        $unit->update(['is_active' => false]);

        $this->audit->append('department', (string) $unit->id, 'archive', $request->user()->id, [
            'name' => $name,
            'archived' => true,
        ]);

        return response()->json(['data' => ['id' => $unit->id, 'archived' => true, 'name' => $name]]);
    }

    public function assignUnitHead(Request $request, Department $unit): JsonResponse
    {
        return $this->setHead($request, 'ZONAL_HEAD', 'scope_zone_id', null, $unit, 'unit');
    }

    // Helper: map department to division array
    protected function divisionToArray(Department $d): array
    {
        return [
            'id' => $d->id,
            'code' => $d->code,
            'name' => $d->name,
            'name_sw' => $d->name_sw,
            'zone_id' => $d->zone_id,
            'parent_department_id' => $d->parent_department_id,
            'unit_type' => $d->unit_type,
            'is_active' => $d->is_active,
            'sort_order' => $d->sort_order,
            'reports_directly_to_leadership' => $d->reports_directly_to_leadership,
            'effective_from' => $d->effective_from,
            'effective_to' => $d->effective_to,
        ];
    }

    protected function divisionDetailToArray(Department $d, ?array $head, int $employeeCount): array
    {
        return array_merge($this->divisionToArray($d), [
            'head' => $head ? [
                'employee_id' => $head['employee_id'],
                'full_name' => $head['full_name'],
            ] : null,
            'employee_count' => $employeeCount,
            'active_employee_count' => $employeeCount,
        ]);
    }

    // Helper: map department to unit array
    protected function unitToArray(Department $d): array
    {
        return [
            'id' => $d->id,
            'code' => $d->code,
            'name' => $d->name,
            'name_sw' => $d->name_sw,
            'zone_id' => $d->zone_id,
            'parent_department_id' => $d->parent_department_id,
            'unit_type' => $d->unit_type,
            'is_active' => $d->is_active,
            'sort_order' => $d->sort_order,
            'reports_directly_to_leadership' => $d->reports_directly_to_leadership,
            'effective_from' => $d->effective_from,
            'effective_to' => $d->effective_to,
        ];
    }

    protected function unitDetailToArray(Department $d, ?array $head, int $employeeCount): array
    {
        return array_merge($this->unitToArray($d), [
            'head' => $head ? [
                'employee_id' => $head['employee_id'],
                'full_name' => $head['full_name'],
            ] : null,
            'employee_count' => $employeeCount,
            'active_employee_count' => $employeeCount,
            'reports_to' => $d->parent?->name ?? '—',
        ]);
    }

    // Reuse existing setHead/clearHead/headOf from the controller
    // (they operate on Department|Zone, so passing a Department works)
    // But we need separate methods since setHead expects Department|Zone type hint.
    // We'll duplicate the logic or use a closure approach.

    protected function setHeadForDepartment(Request $request, Department $department, string $roleId, string $scopeColumn): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', Rule::exists('employees', 'id')],
        ]);

        $guard = $this->permissions->canGrantRole($request->user(), $roleId);
        if (! $guard['ok']) {
            return response()->json([
                'message' => $guard['reason'],
                'errors' => ['employee_id' => [$guard['reason']]],
            ], 422);
        }

        $employee = Employee::findOrFail($data['employee_id']);
        $previousHolders = EmployeeRole::where('role_id', $roleId)
            ->where($scopeColumn, $department->id)
            ->pluck('employee_id')
            ->unique()
            ->all();

        DB::transaction(function () use ($roleId, $scopeColumn, $department, $employee, $request) {
            EmployeeRole::where('role_id', $roleId)->where($scopeColumn, $department->id)->delete();

            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => $roleId,
                $scopeColumn => $department->id,
                'source' => 'head_of_department',
                'granted_by' => $request->user()->id,
                'effective_from' => now()->toDateString(),
            ]);
        });

        foreach (array_unique([...$previousHolders, $employee->id]) as $holderId) {
            $holder = Employee::find($holderId);
            if ($holder?->user) {
                $this->permissions->forget($holder->user);
            }
        }

        $this->audit->append($auditEntity ?? 'department', (string) $department->id, 'head_assign', $request->user()->id, [
            'role_id' => $roleId,
            'employee_id' => $employee->id,
            'previous_employee_ids' => $previousHolders,
        ]);

        return response()->json(['data' => $department->setAttribute('head', $this->headOf($roleId, $scopeColumn, $department->id))]);
    }

    protected function clearHeadForDepartment(Request $request, Department $department, string $roleId, string $scopeColumn): JsonResponse
    {
        $previousHolders = EmployeeRole::where('role_id', $roleId)
            ->where($scopeColumn, $department->id)
            ->pluck('employee_id')
            ->unique()
            ->all();

        EmployeeRole::where('role_id', $roleId)->where($scopeColumn, $department->id)->delete();

        foreach ($previousHolders as $holderId) {
            $holder = Employee::find($holderId);
            if ($holder?->user) {
                $this->permissions->forget($holder->user);
            }
        }

        if ($previousHolders !== []) {
            $this->audit->append($auditEntity ?? 'department', (string) $department->id, 'head_clear', $request->user()->id, [
                'role_id' => $roleId,
                'previous_employee_ids' => $previousHolders,
            ]);
        }

        return response()->json(['data' => $department->setAttribute('head', null)]);
    }

    protected function headOfForDepartment(string $roleId, string $scopeColumn, string $scopeId): ?array
    {
        $grant = EmployeeRole::where('role_id', $roleId)
            ->where($scopeColumn, $scopeId)
            ->with('employee:id,full_name')
            ->first();

        return $this->headPayload($grant);
    }
}
