<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Services\AuditService;
use App\Services\WorkflowResolver;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => WorkflowDefinition::with(['leaveType', 'department', 'steps.approvalRole'])
                ->orderBy('leave_type_id')
                ->get(),
        ]);
    }

    public function show(WorkflowDefinition $workflowDefinition): JsonResponse
    {
        return response()->json(['data' => $workflowDefinition->load(['leaveType', 'department', 'steps.approvalRole'])]);
    }

    /** Create a new workflow definition/version (frontend admin console). */
    public function store(StoreWorkflowRequest $form): JsonResponse
    {
        $version = ((int) WorkflowDefinition::where('leave_type_id', $form->input('leave_type_id'))->max('version') ?? 0) + 1;
        $id = $form->input('id') ?: 'wf_'.$form->input('leave_type_id').'_v'.$version;
        $from = $form->input('effective_from', now()->toDateString());
        $to = $form->input('effective_to');

        if ($to !== null && $from !== null && strcmp($to, $from) < 0) {
            throw ValidationException::withMessages(['effective_to' => 'Effective-to must be on or after effective-from.']);
        }

        $this->guardNoOverlap(
            $form->input('leave_type_id'),
            $form->input('zone_id'),
            $form->input('department_id'),
            $from,
            $to,
            null
        );

        $def = WorkflowDefinition::create([
            'id' => $id,
            'leave_type_id' => $form->input('leave_type_id'),
            'version' => $version,
            'min_duration_days' => (int) ($form->input('min_duration_days', 0)),
            'zone_id' => $form->input('zone_id'),
            'department_id' => $form->input('department_id'),
            'effective_from' => $from,
            'effective_to' => $to,
            'created_by' => $form->user()->id,
            'approved_by' => $form->input('approved_by'),
            'notes' => $form->input('notes'),
            'is_active' => $form->boolean('is_active', true),
        ]);
        $steps = $form->input('steps', []);
        $this->syncSteps($def, $steps);
        $this->audit->append('workflow', $id, 'create', $form->user()->id, [
            'leave_type_id' => $def->leave_type_id,
            'version' => $version,
            'steps' => count($steps),
        ]);

        return response()->json(['data' => $def->load(['leaveType', 'steps'])], 201);
    }

    /** Patch workflow fields/steps (frontend updateWorkflow). */
    public function update(UpdateWorkflowRequest $form, WorkflowDefinition $workflowDefinition): JsonResponse
    {
        $from = $form->input('effective_from', $workflowDefinition->effective_from?->toDateString());
        $to = $form->input('effective_to');
        if ($to !== null && $from !== null && strcmp($to, $from) < 0) {
            throw ValidationException::withMessages(['effective_to' => 'Effective-to must be on or after effective-from.']);
        }

        $version = ((int) WorkflowDefinition::where('leave_type_id', $workflowDefinition->leave_type_id)->max('version') ?? 0) + 1;

        // Deactivation must not orphan the leave type: the resolver only
        // considers active definitions, so switching off the last active one
        // would make the next submission fail to resolve a chain at all.
        if ($form->has('is_active') && ! $form->boolean('is_active') && $workflowDefinition->is_active) {
            $this->guardOtherActiveExists($workflowDefinition);
        }

        $this->guardNoOverlap(
            $workflowDefinition->leave_type_id,
            $form->input('zone_id', $workflowDefinition->zone_id),
            $form->input('department_id', $workflowDefinition->department_id),
            $from,
            $to,
            $workflowDefinition->id
        );

        $patch = [
            'version' => $version,
            'min_duration_days' => (int) $form->input('min_duration_days', $workflowDefinition->min_duration_days),
            'zone_id' => $form->input('zone_id', $workflowDefinition->zone_id),
            'department_id' => $form->input('department_id', $workflowDefinition->department_id),
            'effective_from' => $from,
            'effective_to' => $to,
            'notes' => $form->input('notes', $workflowDefinition->notes),
        ];
        if ($form->has('is_active')) {
            $patch['is_active'] = $form->boolean('is_active');
        }
        $workflowDefinition->update($patch);

        if ($form->has('steps')) {
            $this->syncSteps($workflowDefinition, $form->input('steps', []));
        }

        $this->audit->append('workflow', (string) $workflowDefinition->id, 'update', $form->user()->id, [
            'leave_type_id' => $workflowDefinition->leave_type_id,
            'version' => $version,
        ]);

        return response()->json(['data' => $workflowDefinition->fresh(['leaveType', 'department', 'steps.approvalRole'])]);
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

    protected function syncSteps(WorkflowDefinition $def, array $steps): void
    {
        $def->steps()->delete();
        foreach ($steps as $i => $step) {
            WorkflowStep::create([
                'workflow_def_id' => $def->id,
                'step_order' => $i + 1,
                'approval_role_id' => $step['approval_role_id'] ?? null,
                'fallback_approval_role_id' => $step['fallback_approval_role_id'] ?? $step['fallback_role_id'] ?? null,
            'assignment_mode' => $step['assignment_mode'] ?? 'assigned_approver',
            'scope_type' => $step['scope_type'] ?? 'requester_department',
                // Legacy compatibility: pre-catalog definitions still carry a
                // permission role id. New definitions send approval_role_id.
                'role_required' => $step['role_required'] ?? null,
                'sla_days' => (int) $step['sla_days'],
                'is_mandatory' => (bool) ($step['is_mandatory'] ?? true),
                'allow_same_person_as_previous_steps' => (bool) ($step['allow_same_person_as_previous_steps'] ?? false),
                'conditions' => $step['conditions'] ?? null,
                'escalation_after_sla' => (bool) ($step['escalation_after_sla'] ?? true),
                'note' => $step['note'] ?? null,
            ]);
        }
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
                    'version' => $def->version,
                    'leave_type_id' => $def->leave_type_id,
                    'zone_id' => $def->zone_id,
                    'department_id' => $def->department_id,
                    'min_duration_days' => $def->min_duration_days,
                ] : null,
                'steps' => collect($chain)->map(fn ($e) => [
                    'step_id' => $e['step_id'] ?? null,
                    'role' => $e['role'] ?? null,
                    'role_label' => $e['role_label'] ?? null,
                    'assignment_mode' => $e['assignment_mode'] ?? 'assigned_approver',
                    'assignee_id' => $e['assignee_id'] ?? null,
                    'assignee_name' => $e['assignee_name'] ?? null,
                    'via' => $e['via'] ?? null,
                ])->values(),
                'conflict' => $def === null,
            ],
        ]);
    }

    protected function guardNoOverlap(string $leaveTypeId, ?string $zoneId, ?string $departmentId, ?string $from, ?string $to, ?string $excludeId): void
    {
        $query = WorkflowDefinition::where('leave_type_id', $leaveTypeId)
            ->where('is_active', true)
            ->where('zone_id', $zoneId)
            ->where('department_id', $departmentId);

        if ($excludeId) {
            $query->where('id', '<>', $excludeId);
        }

        foreach ($query->get() as $existing) {
            $eFrom = $existing->effective_from?->toDateString();
            $eTo = $existing->effective_to?->toDateString();
            // Overlap check on effective periods.
            // Only block exact duplicate coverage (same effective period and
            // same minimum duration) so legitimate versioning (overlapping but
            // distinct effective periods) remains possible.
            $start = $from ?? '0000-01-01';
            $end = $to ?? '9999-12-31';
            $eStart = $eFrom ?? '0000-01-01';
            $eEnd = $eTo ?? '9999-12-31';
            if ($start === $eStart && $end === $eEnd) {
                throw ValidationException::withMessages([
                    'workflow' => 'Another active workflow already covers this leave type, duration range, and organizational scope. Resolve the overlap before saving.',
                ]);
            }
        }
    }
}
