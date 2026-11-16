<?php

use App\Models\WorkflowDefinition;
use App\Services\WorkflowResolver;

/**
 * Duration defaults after the "Advanced Duration Rule" UI was removed.
 *
 * The workflow builder no longer collects min/max duration: every workflow
 * created through the UI is an all-duration workflow (min=1, max=null).
 * Legacy rows that carry a custom band must keep resolving correctly.
 */

function wddRetire(string $leaveTypeId): void
{
    \Illuminate\Support\Facades\DB::table('workflow_definitions')
        ->where('leave_type_id', $leaveTypeId)
        ->update(['is_active' => false]);
}

it('creates a workflow with the default duration band (1 day, no upper limit)', function () {
    $admin = apiLogin('u1');
    wddRetire('lt5');

    $res = $this->postJson('/api/workflows', [
        'workflow_title' => 'Default duration',
        'leave_type_id' => 'lt5',
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $def = WorkflowDefinition::findOrFail($res->json('data.id'));

    expect($def->min_duration_days)->toBe(1)
        ->and($def->max_duration_days)->toBeNull();
});

it('keeps legacy workflows with a custom duration band resolving', function () {
    wddRetire('lt5');

    $legacy = \App\Models\WorkflowDefinition::create([
        'id' => 'wf_legacy_band',
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Legacy 1-5 days',
        'version' => 999,
        'min_duration_days' => 1,
        'max_duration_days' => 5,
        'zone_id' => null,
        'department_id' => null,
        'is_active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u1',
    ]);
    \App\Models\WorkflowStep::create([
        'workflow_def_id' => $legacy->id,
        'step_order' => 1,
        'approval_role_id' => 'hr_reviewer',
        'assignment_mode' => 'shared_queue',
        'scope_type' => 'requester_department',
        'sla_days' => 2,
        'is_mandatory' => true,
    ]);

    $employee = \App\Models\Employee::findOrFail('e7');

    $short = new \App\Models\LeaveRequest([
        'ref' => 'SYN-short',
        'employee_id' => $employee->id,
        'leave_type_id' => 'lt5',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(32)->toDateString(),
        'days' => 3,
        'status' => 'draft',
        'reason' => 'Resolution fixture',
    ]);
    $short->setRelation('employee', $employee);
    $short->submitted_at = now();

    $long = new \App\Models\LeaveRequest([
        'ref' => 'SYN-long',
        'employee_id' => $employee->id,
        'leave_type_id' => 'lt5',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(39)->toDateString(),
        'days' => 10,
        'status' => 'draft',
        'reason' => 'Resolution fixture',
    ]);
    $long->setRelation('employee', $employee);
    $long->submitted_at = now();

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveDefinition($short)?->id)->toBe('wf_legacy_band')
        ->and($resolver->resolveDefinition($long))->toBeNull();
});
