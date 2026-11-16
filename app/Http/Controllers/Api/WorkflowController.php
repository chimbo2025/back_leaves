<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use App\Models\ApprovalRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Models\Zone;
use App\Services\AuditService;
use App\Services\WorkflowOverlapGuard;
use App\Services\WorkflowResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkflowController extends Controller
{
    /**
     * Approval-role catalog code => legacy permission role id. The legacy
     * pointer is kept in sync for pre-catalog consumers (chains show SUPERVISOR
     * / HR / FINAL_AUTHORITY) while routing continues to use approval_role_id.
     * Catalog roles with no legacy twin stay null.
     */
    protected const LEGACY_ROLE_BY_APPROVAL_ROLE = [
        'supervisor' => 'SUPERVISOR',
        'department_head' => 'DEPT_HEAD',
        'zonal_head' => 'ZONAL_HEAD',
        'hr_reviewer' => 'HR',
        'final_authority' => 'FINAL_AUTHORITY',
        'accounting_reviewer' => 'ACCOUNTING',
    ];

    /**
     * Sensible routing defaults per approval role (spec §11).
     *
     * @var array<string, array{0:string, 1:string}>
     */
    protected const DEFAULT_STEP_CONFIG = [
        'hr_reviewer' => ['shared_queue', 'office_wide'],
        'final_authority' => ['assigned_approver', 'office_wide'],
        'deputy_dpp' => ['assigned_approver', 'office_wide'],
        'accounting_reviewer' => ['shared_queue', 'office_wide'],
    ];

    public function __construct(
        protected AuditService $audit,
        protected WorkflowOverlapGuard $overlaps,
    ) {}

    /**
     * Paginated/filterable list.
     *
     * Backward compatibility: a request without `page`/`per_page` returns the
     * historical `{data: [...]}` array shape (the leave-context loader and old
     * clients depend on it). A request with either parameter returns Laravel's
     * paginator payload under `data` plus the standard sibling metadata:
     * current_page, per_page, last_page, total, from, to.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowDefinition::with(['leaveType', 'department', 'zone', 'steps.approvalRole']);

        $this->applyListFilters($query, $request);

        $this->applyListSort($query, $request);

        $wantsPagination = $request->has('page') || $request->has('per_page');

        if (! $wantsPagination) {
            return response()->json([
                'data' => $query->get()->map(fn (WorkflowDefinition $def) => $this->decorate($def))->all(),
            ]);
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, (int) $request->input('page', 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (WorkflowDefinition $def) => $this->decorate($def))->all(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    public function show(WorkflowDefinition $workflowDefinition): JsonResponse
    {
        $workflowDefinition->load(['leaveType', 'department', 'zone', 'steps.approvalRole']);

        return response()->json(['data' => $this->decorate($workflowDefinition)]);
    }

    /** Create a new workflow definition/version (frontend admin console). */
    public function store(StoreWorkflowRequest $form): JsonResponse
    {
        $leaveTypeId = (string) $form->input('leave_type_id');
        $title = trim((string) $form->input('workflow_title'));
        $minDays = max(1, (int) $form->input('min_duration_days', 1));
        $maxDays = $form->input('max_duration_days');
        $maxDays = $maxDays === null || $maxDays === '' ? null : (int) $maxDays;
        $zoneId = $form->input('zone_id') ?: null;
        $departmentId = $form->input('department_id') ?: null;
        $from = $form->input('effective_from', now()->toDateString());
        $to = $form->input('effective_to');
        $isActive = $form->boolean('is_active', true);

        if ($to !== null && $from !== null && strcmp((string) $to, (string) $from) < 0) {
            throw ValidationException::withMessages(['effective_to' => 'Effective-to must be on or after effective-from.']);
        }

        // One active rule per leave type / scope / duration band. Ambiguity is
        // a configuration error, not something an employee should discover at
        // submission time.
        $this->overlaps->assertNoConflict([
            'leave_type_id' => $leaveTypeId,
            'workflow_title' => $title,
            'min_duration_days' => $minDays,
            'max_duration_days' => $maxDays,
            'zone_id' => $zoneId,
            'department_id' => $departmentId,
            'effective_from' => $from,
            'effective_to' => $to,
            'is_active' => $isActive,
        ]);

        $version = ((int) WorkflowDefinition::where('leave_type_id', $leaveTypeId)->max('version') ?? 0) + 1;
        $id = $form->input('id') ?: 'wf_' . $leaveTypeId . '_v' . $version;

        $def = WorkflowDefinition::create([
            'id' => $id,
            'workflow_title' => $title,
            'leave_type_id' => $leaveTypeId,
            'version' => $version,
            'min_duration_days' => $minDays,
            'max_duration_days' => $maxDays,
            'zone_id' => $zoneId,
            'department_id' => $departmentId,
            'effective_from' => $from,
            'effective_to' => $to,
            'created_by' => $form->user()->id,
            'approved_by' => $form->input('approved_by'),
            'notes' => $form->input('notes'),
            'is_active' => $isActive,
        ]);

        $steps = $form->input('steps', []);
        $this->syncSteps($def, $steps);

        $this->audit->append('workflow', $id, 'create', $form->user()->id, [
            'leave_type_id' => $def->leave_type_id,
            'workflow_title' => $title,
            'version' => $version,
            'scope' => $this->overlaps->scopeLabel($zoneId, $departmentId),
            'steps' => count($steps),
        ]);

        return response()->json(['data' => $this->decorate($def->load(['leaveType', 'department', 'zone', 'steps.approvalRole']))], 201);
    }

    /** Patch workflow fields/steps (frontend updateWorkflow). */
    public function update(UpdateWorkflowRequest $form, WorkflowDefinition $workflowDefinition): JsonResponse
    {
        $from = $form->input('effective_from', $workflowDefinition->effective_from?->toDateString());
        $to = $form->has('effective_to') ? $form->input('effective_to') : $workflowDefinition->effective_to?->toDateString();

        if ($to !== null && $from !== null && strcmp((string) $to, (string) $from) < 0) {
            throw ValidationException::withMessages(['effective_to' => 'Effective-to must be on or after effective-from.']);
        }

        $minDays = $form->has('min_duration_days')
            ? max(1, (int) $form->input('min_duration_days'))
            : max(1, (int) $workflowDefinition->min_duration_days);

        $maxDays = $form->has('max_duration_days')
            ? ($form->input('max_duration_days') === null || $form->input('max_duration_days') === '' ? null : (int) $form->input('max_duration_days'))
            : $workflowDefinition->max_duration_days;

        $zoneId = $form->has('zone_id') ? ($form->input('zone_id') ?: null) : $workflowDefinition->zone_id;
        $departmentId = $form->has('department_id') ? ($form->input('department_id') ?: null) : $workflowDefinition->department_id;
        $title = $form->has('workflow_title')
            ? trim((string) $form->input('workflow_title'))
            : (string) ($workflowDefinition->workflow_title ?: '');

        $isActive = $form->has('is_active') ? $form->boolean('is_active') : (bool) $workflowDefinition->is_active;

        // Deactivation must not orphan the leave type: the resolver only
        // considers active definitions, so switching off the last active one
        // would make the next submission fail to resolve a chain at all.
        if ($form->has('is_active') && ! $isActive && $workflowDefinition->is_active) {
            $this->guardOtherActiveExists($workflowDefinition);
        }

        // Never leave a legacy row nameless after an update.
        if ($title === '') {
            $title = $this->generatedTitle($workflowDefinition->leave_type_id, $workflowDefinition->version);
        }

        $this->overlaps->assertNoConflict([
            'leave_type_id' => $workflowDefinition->leave_type_id,
            'workflow_title' => $title,
            'min_duration_days' => $minDays,
            'max_duration_days' => $maxDays,
            'zone_id' => $zoneId,
            'department_id' => $departmentId,
            'effective_from' => $from,
            'effective_to' => $to,
            'is_active' => $isActive,
        ], $workflowDefinition->id);

        $patch = [
            'workflow_title' => $title,
            'min_duration_days' => $minDays,
            'max_duration_days' => $maxDays,
            'zone_id' => $zoneId,
            'department_id' => $departmentId,
            'effective_from' => $from,
            'effective_to' => $to,
            'notes' => $form->input('notes', $workflowDefinition->notes),
        ];
        if ($form->has('is_active')) {
            $patch['is_active'] = $isActive;
        }
        $workflowDefinition->update($patch);

        if ($form->has('steps')) {
            $this->syncSteps($workflowDefinition, $form->input('steps', []));
        }

        $this->audit->append('workflow', (string) $workflowDefinition->id, 'update', $form->user()->id, [
            'leave_type_id' => $workflowDefinition->leave_type_id,
            'workflow_title' => $title,
            'version' => $workflowDefinition->version,
        ]);

        return response()->json([
            'data' => $this->decorate($workflowDefinition->fresh(['leaveType', 'department', 'zone', 'steps.approvalRole'])),
        ]);
    }

    /**
     * Dry-run used by the live builder preview. Accepts the same shape as
     * store/update, validates the scope/duration, and reports where the chain
     * would apply plus any active definition it would collide with.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'nullable', 'string'],
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')],
            'workflow_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'min_duration_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'max_duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
            'zone_id' => ['sometimes', 'nullable', Rule::exists('zones', 'id')],
            'department_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')],
            'steps' => ['sometimes', 'array'],
            'steps.*.approval_role_id' => ['sometimes', 'nullable', Rule::exists('approval_roles', 'id')],
            'steps.*.assignment_mode' => ['sometimes', 'nullable', 'string'],
            'steps.*.scope_type' => ['sometimes', 'nullable', 'string'],
            'steps.*.is_mandatory' => ['sometimes', 'boolean'],
            'steps.*.note' => ['sometimes', 'nullable', 'string'],
        ]);

        $min = max(1, (int) ($data['min_duration_days'] ?? 1));
        $max = isset($data['max_duration_days']) && $data['max_duration_days'] !== null
            ? (int) $data['max_duration_days']
            : null;
        $zoneId = $data['zone_id'] ?? null;
        $departmentId = $data['department_id'] ?? null;
        $leaveType = LeaveType::find($data['leave_type_id']);

        $conflict = $this->overlaps->findConflict([
            'leave_type_id' => $data['leave_type_id'],
            'workflow_title' => $data['workflow_title'] ?? '',
            'min_duration_days' => $min,
            'max_duration_days' => $max,
            'zone_id' => $zoneId,
            'department_id' => $departmentId,
            'is_active' => true,
        ], $data['id'] ?? null);

        $message = null;
        if ($conflict) {
            $typeName = $leaveType?->name ?? (string) $data['leave_type_id'];
            $message = 'Another active ' . $typeName . ' workflow ('
                . ($conflict->workflow_title ?: 'v' . $conflict->version) . ') already covers '
                . $this->overlaps->scopeLabel($conflict->zone_id, $conflict->department_id)
                . ' for ' . $this->overlaps->durationLabel((int) $conflict->min_duration_days, $conflict->max_duration_days) . '.';
        }

        $steps = collect($data['steps'] ?? [])->values()->map(function ($step, $i) {
            $role = ! empty($step['approval_role_id'])
                ? ApprovalRole::find($step['approval_role_id'])
                : null;
            [$defaultMode, $defaultScope] = $this->defaultStepConfig($role?->code);

            return [
                'step_order' => $i + 1,
                'approval_role_id' => $step['approval_role_id'] ?? null,
                'approval_role_name' => $role?->name,
                'assignment_mode' => $step['assignment_mode'] ?? $defaultMode,
                'scope_type' => $step['scope_type'] ?? $defaultScope,
                'is_mandatory' => (bool) ($step['is_mandatory'] ?? true),
                'note' => $step['note'] ?? null,
            ];
        })->all();

        $policy = LeavePolicy::where('leave_type_id', $data['leave_type_id'])
            ->where('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->orderByDesc('version')
            ->first();

        $reasons = [];
        if ($policy && (float) $policy->max_days_per_request >= 60) {
            $reasons[] = 'the policy allows 60+ days per request';
        }
        if ($leaveType && in_array($leaveType->code, ['MATERNITY', 'STUDY', 'WITHOUT_PAY', 'UNPAID'], true)) {
            $reasons[] = strtolower($leaveType->name) . ' always requires final authority';
        }
        $reasons[] = 'a request lasts 21 days or more';

        return response()->json([
            'data' => [
                'conflict' => $conflict !== null,
                'message' => $message,
                'conflict_title' => $conflict?->workflow_title,
                'applies_to' => $this->scopeSentence($zoneId, $departmentId),
                'duration_label' => $this->overlaps->durationLabel($min, $max),
                'leave_type_name' => $leaveType?->name,
                'steps' => $steps,
                'final_authority' => [
                    'auto' => $reasons !== [],
                    'reasons' => $reasons,
                ],
            ],
        ]);
    }

    /**
     * Delete an unused workflow definition.
     *
     * Deletion is an update-class operation by design: the stacked
     * policies.edit_policy + approval_workflows.update codes (identical
     * holder set to every other workflow write) cover it, so no dedicated
     * delete code enters the catalogue and the RBAC matrix stays honest —
     * whoever may rewrite a chain may remove one that never ran.
     *
     * Two guards protect real data:
     *   1. a definition whose steps carry assignments is audit history —
     *      deleting it would null the assignments' step pointers, so it can
     *      only be deactivated;
     *   2. a leave type must always keep at least one ACTIVE definition, or
     *      its next submission would resolve to nothing.
     */
    public function destroy(WorkflowDefinition $workflowDefinition): JsonResponse
    {
        $inUse = DB::table('workflow_assignments')
            ->whereIn('step_id', $workflowDefinition->steps()->pluck('id'))
            ->count();
        if ($inUse > 0) {
            return response()->json(['message' => "This workflow is attached to {$inUse} request(s) — history is locked. Deactivate it instead."], 422);
        }

        $this->guardOtherActiveExists($workflowDefinition);

        $id = (string) $workflowDefinition->id;
        $leaveTypeId = $workflowDefinition->leave_type_id;

        DB::transaction(function () use ($workflowDefinition) {
            // Steps cascade at the DB level; deleted explicitly so the intent
            // survives a foreign-key behaviour change.
            $workflowDefinition->steps()->delete();
            $workflowDefinition->delete();
        });

        $this->audit->append('workflow', $id, 'delete', request()->user()->id, [
            'leave_type_id' => $leaveTypeId,
        ]);

        return response()->json(['message' => 'Workflow deleted.']);
    }

    /** A leave type must always keep at least one active definition. */
    protected function guardOtherActiveExists(WorkflowDefinition $workflowDefinition): void
    {
        $othersActive = WorkflowDefinition::where('leave_type_id', $workflowDefinition->leave_type_id)
            ->where('is_active', true)
            ->where('id', '!=', $workflowDefinition->id)
            ->exists();

        if (! $othersActive) {
            throw ValidationException::withMessages([
                'is_active' => 'A leave type must keep at least one active workflow — create its replacement first.',
            ]);
        }
    }

    /**
     * Persist steps. New catalog steps get the spec §11 routing defaults when
     * the caller omits them, and a legacy `role_required` pointer derived from
     * the catalog role (never the other way round — routing reads
     * approval_role_id first).
     */
    protected function syncSteps(WorkflowDefinition $def, array $steps): void
    {
        $def->steps()->delete();

        foreach ($steps as $i => $step) {
            $approvalRoleId = $step['approval_role_id'] ?? null;
            $role = $approvalRoleId ? ApprovalRole::find($approvalRoleId) : null;
            $roleCode = $role?->code;

            [$defaultMode, $defaultScope] = $this->defaultStepConfig($roleCode);

            WorkflowStep::create([
                'workflow_def_id' => $def->id,
                'step_order' => $i + 1,
                'approval_role_id' => $approvalRoleId,
                'fallback_approval_role_id' => $step['fallback_approval_role_id'] ?? $step['fallback_role_id'] ?? null,
                'assignment_mode' => $step['assignment_mode'] ?? $defaultMode,
                'scope_type' => $step['scope_type'] ?? $defaultScope,
                // Legacy pointer: explicit value wins, otherwise derive it from
                // the catalog role for pre-catalog consumers. Never built from
                // strtoupper(approval_role_id) — that produced invalid role ids
                // (HR_REVIEWER, DEPARTMENT_HEAD) and 422s.
                'role_required' => $step['role_required'] ?? (self::LEGACY_ROLE_BY_APPROVAL_ROLE[$roleCode] ?? null),
                'sla_days' => (int) $step['sla_days'],
                'is_mandatory' => (bool) ($step['is_mandatory'] ?? true),
                'allow_same_person_as_previous_steps' => (bool) ($step['allow_same_person_as_previous_steps'] ?? false),
                'conditions' => $step['conditions'] ?? null,
                'escalation_after_sla' => (bool) ($step['escalation_after_sla'] ?? false),
                'note' => $step['note'] ?? null,
            ]);
        }
    }

    /** @return array{0:string, 1:string} [assignment_mode, scope_type] */
    protected function defaultStepConfig(?string $roleCode): array
    {
        return self::DEFAULT_STEP_CONFIG[$roleCode] ?? ['assigned_approver', 'requester_department'];
    }

    public function preview(Request $request): JsonResponse
    {
        $employee = Employee::find($request->input('employee_id'));
        $leaveType = LeaveType::find($request->input('leave_type_id'));
        $days = (int) $request->input('days', 1);
        $effectiveDate = $request->input('effective_date', now()->toDateString());

        if (! $employee || ! $leaveType) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee and leave type are required.',
            ]);
        }

        $synthetic = new LeaveRequest([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'days' => $days,
            'start_date' => $effectiveDate,
            'end_date' => $effectiveDate,
            'status' => 'draft',
        ]);
        $synthetic->setRelation('employee', $employee);
        $synthetic->submitted_at = Carbon::parse($effectiveDate);

        $resolver = resolve(WorkflowResolver::class);
        $def = $resolver->resolveDefinition($synthetic);
        $chain = $def ? $resolver->resolveWorkflow($synthetic) : [];

        return response()->json([
            'data' => [
                'resolved_workflow' => $def ? [
                    'id' => $def->id,
                    'workflow_title' => $def->workflow_title,
                    'version' => $def->version,
                    'leave_type_id' => $def->leave_type_id,
                    'zone_id' => $def->zone_id,
                    'department_id' => $def->department_id,
                    'min_duration_days' => $def->min_duration_days,
                    'max_duration_days' => $def->max_duration_days,
                ] : null,
                'steps' => collect($chain)->map(fn ($e) => [
                    'step_id' => $e['step_id'] ?? null,
                    'role' => $e['role'] ?? null,
                    'role_label' => $e['role_label'] ?? null,
                    'assignment_mode' => $e['assignment_mode'] ?? 'assigned_approver',
                    'assignee_id' => $e['assignee_id'] ?? null,
                    'assignee_name' => $e['assignee_name'] ?? null,
                    'assignee_initials' => $e['assignee_initials'] ?? null,
                    'via' => $e['via'] ?? null,
                ])->values(),
                'conflict' => $def === null,
            ],
        ]);
    }

    /** @param Builder<WorkflowDefinition> $query */
    protected function applyListFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where('workflow_title', 'like', '%' . $search . '%');
        }

        if ($leaveTypeId = $request->input('leave_type_id')) {
            $query->where('leave_type_id', $leaveTypeId);
        }

        $status = (string) $request->input('status', '');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $scope = (string) $request->input('scope', '');
        if ($scope === 'office_wide') {
            $query->whereNull('zone_id')->whereNull('department_id');
        } elseif ($scope === 'zone') {
            $query->whereNotNull('zone_id')->whereNull('department_id');
        } elseif ($scope === 'department') {
            $query->whereNotNull('department_id')->whereHas('department', fn (Builder $q) => $q->whereNotIn('unit_type', ['division', 'independent_unit']));
        } elseif ($scope === 'division') {
            $query->whereHas('department', fn (Builder $q) => $q->where('unit_type', 'division'));
        } elseif ($scope === 'unit') {
            $query->whereHas('department', fn (Builder $q) => $q->whereIn('unit_type', ['independent_unit', 'unit']));
        }

        if ($zoneId = $request->input('zone_id')) {
            $query->where('zone_id', $zoneId);
        }

        if ($departmentId = $request->input('department_id')) {
            $query->where('department_id', $departmentId);
        }
    }

    /** @param Builder<WorkflowDefinition> $query */
    protected function applyListSort(Builder $query, Request $request): void
    {
        $sort = (string) $request->input('sort', '');
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortable = ['title', 'leave_type', 'version', 'created_at', 'updated_at'];

        if ($sort === 'title') {
            $query->orderBy('workflow_title', $direction);
        } elseif ($sort === 'leave_type') {
            $query->orderBy(
                LeaveType::select('name')->whereColumn('leave_types.id', 'workflow_definitions.leave_type_id'),
                $direction,
            );
        } elseif ($sort === 'version') {
            $query->orderBy('version', $direction);
        } elseif ($sort === 'created_at') {
            $query->orderBy('created_at', $direction);
        } elseif ($sort === 'updated_at') {
            $query->orderBy('updated_at', $direction);
        } else {
            // Default: newest edits first, stable by title.
            $query->orderBy('updated_at', 'desc')->orderBy('workflow_title');
        }

        if ($sort !== '' && ! in_array($sort, $sortable, true)) {
            $query->orderBy('updated_at', 'desc');
        }
    }

    /** Wire format shared by list/detail/create/update. */
    protected function decorate(WorkflowDefinition $def): array
    {
        $leaveType = $def->leaveType;
        $department = $def->department;
        $zone = $def->zone;

        return [
            ...$def->toArray(),
            'workflow_title' => $def->workflow_title
                ?: $this->generatedTitle($def->leave_type_id, (int) $def->version),
            'leave_type_name' => $leaveType?->name,
            'zone_name' => $zone?->name,
            'department_name' => $department?->name,
            'department_unit_type' => $department?->unit_type,
            'scope_kind' => $department ? 'department' : ($zone ? 'zone' : 'office_wide'),
            'scope_label' => $department
                ? $department->name . ($department->unit_type ? ' · ' . str_replace('_', ' ', $department->unit_type) : '')
                : ($zone?->name ?? 'Entire Office'),
            'applies_to' => $this->scopeSentence($def->zone_id, $def->department_id),
            'duration_label' => $this->overlaps->durationLabel(
                max(1, (int) $def->min_duration_days),
                $def->max_duration_days,
            ),
        ];
    }

    protected function scopeSentence(?string $zoneId, ?string $departmentId): string
    {
        if ($departmentId) {
            $dept = Department::find($departmentId);

            return 'Employees in ' . ($dept?->name ?? $departmentId)
                . ($dept?->unit_type ? ' (' . str_replace('_', ' ', $dept->unit_type) . ')' : '');
        }

        if ($zoneId) {
            $zone = Zone::find($zoneId);

            return 'Employees in ' . ($zone?->name ?? $zoneId) . ' zone';
        }

        return 'All employees (entire office)';
    }

    protected function generatedTitle(string $leaveTypeId, int $version): string
    {
        $label = LeaveType::find($leaveTypeId)?->name ?: $leaveTypeId;

        return trim($label) . ' Workflow v' . max(1, $version);
    }
}
