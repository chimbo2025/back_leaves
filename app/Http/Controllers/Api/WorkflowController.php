<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => WorkflowDefinition::with(['leaveType', 'department', 'zone', 'steps'])
                ->orderBy('leave_type_id')
                ->get(),
        ]);
    }

    public function show(WorkflowDefinition $workflowDefinition): JsonResponse
    {
        return response()->json(['data' => $workflowDefinition->load(['leaveType', 'department', 'zone', 'steps'])]);
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

        return response()->json(['data' => $workflowDefinition->fresh(['leaveType', 'department', 'zone', 'steps'])]);
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
                'role_required' => $step['role_required'],
                'sla_days' => (int) $step['sla_days'],
                'is_mandatory' => (bool) ($step['is_mandatory'] ?? true),
                'escalation_after_sla' => (bool) ($step['escalation_after_sla'] ?? true),
                'note' => $step['note'] ?? null,
            ]);
        }
    }
}
