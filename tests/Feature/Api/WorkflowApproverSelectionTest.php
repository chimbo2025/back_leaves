<?php

use App\Models\AuditLog;
use App\Models\Delegation;
use App\Models\LeaveRequest;
use App\Models\WorkflowAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Workflow Builder — explicit per-step approver selection.
 *
 * Every step names one employee drawn from the holders of its approval role.
 * These tests cover the eight acceptance cases: holder filtering, storing the
 * approver, routing to that employee, delegation, self-approval refusal,
 * invalid employee/role combinations, overlap prevention, and the enriched
 * audit payload.
 */

function wasRetire(string $leaveTypeId): void
{
    DB::table('workflow_definitions')
        ->where('leave_type_id', $leaveTypeId)
        ->update(['is_active' => false]);
}

/** Create a one-step workflow through the API and return its definition id. */
function wasCreateWorkflow(string $roleId, string $approverId, array $overrides = []): string
{
    $payload = array_merge([
        'workflow_title' => 'Approver selection ' . uniqid(),
        'leave_type_id' => 'lt1',
        'strict_approvers' => true,
        'steps' => [[
            'approval_role_id' => $roleId,
            'approver_employee_id' => $approverId,
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'office_wide',
            'sla_days' => 2,
        ]],
    ], $overrides);

    return test()->postJson('/api/workflows', $payload, ['Authorization' => 'Bearer ' . apiLogin('u1')])
        ->assertStatus(201)
        ->json('data.id');
}

/** Create and submit a leave request as the given user; returns the request id. */
function wasSubmitLeave(string $username, string $leaveTypeId = 'lt1', int $days = 5): int
{
    $token = apiLogin($username);

    $id = test()->postJson('/api/requests', [
        'leave_type_id' => $leaveTypeId,
        'start_date' => now()->addDays(40)->toDateString(),
        'end_date' => now()->addDays(40 + $days - 1)->toDateString(),
        'reason' => 'Explicit approver routing check.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201)->json('data.id');

    test()->postJson("/api/requests/{$id}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    return (int) $id;
}

it('lists only employees who hold the selected approval role', function () {
    $token = apiLogin('u1');

    $departmentHeads = $this->getJson('/api/workflows/role-holders/department_head', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json('data');
    $ids = collect($departmentHeads)->pluck('id')->all();

    // Seeded department-head holders: e2, e3, e4, e5, e6.
    expect($ids)->toContain('e2', 'e3', 'e4', 'e5', 'e6')
        ->and($ids)->not->toContain('e12') // plain employee, holds no approval role
        ->and(collect($departmentHeads)->every(fn ($e) => $e['is_active'] === true))->toBeTrue();

    // Catalog role with a single holder.
    expect(collect($this->getJson('/api/workflows/role-holders/hr_reviewer', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->json('data'))->pluck('id')->all())->toBe(['e3']);

    // final_authority has an explicit grant list, so only actual holders are
    // offered. Reporting-line supervisors are a fallback for "both" roles
    // only when nobody holds the role explicitly — they must never be unioned
    // on top of the grant list.
    $finalAuthority = collect($this->getJson('/api/workflows/role-holders/final_authority', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->json('data'))->pluck('id')->all();
    expect($finalAuthority)->toBe(['e1']);

    // Supervisors come from reporting lines *and* legacy grants, so the
    // legacy-only holder e7 must appear alongside reporting-line supervisors.
    $supervisors = collect($this->getJson('/api/workflows/role-holders/supervisor', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->json('data'))->pluck('id')->all();
    expect($supervisors)->toContain('e7', 'e1');

    $this->getJson('/api/workflows/role-holders/not_a_role', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('approval_role');
});

it('stores the selected approver and coerces the step to a single approver', function () {
    wasRetire('lt1');

    $created = $this->postJson('/api/workflows', [
        'workflow_title' => 'Annual — explicit approver',
        'leave_type_id' => 'lt1',
        'strict_approvers' => true,
        'steps' => [[
            'approval_role_id' => 'department_head',
            'approver_employee_id' => 'e2',
            // Contradictory legacy inputs must not survive when an approver is
            // named: the step is an assigned approver with no fallback role.
            'assignment_mode' => 'shared_queue',
            'fallback_approval_role_id' => 'supervisor',
            'scope_type' => 'office_wide',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => 'Bearer ' . apiLogin('u1')])->assertStatus(201);

    $id = $created->json('data.id');

    $step = DB::table('workflow_steps')->where('workflow_def_id', $id)->first();
    expect($step->approver_employee_id)->toBe('e2')
        ->and($step->assignment_mode)->toBe('assigned_approver')
        ->and($step->fallback_approval_role_id)->toBeNull();

    $created->assertJsonPath('data.steps.0.approver_employee_id', 'e2')
        ->assertJsonPath('data.steps.0.approver.full_name', 'Salma Ali Juma')
        ->assertJsonPath('data.steps.0.assignment_mode', 'assigned_approver')
        ->assertJsonPath('data.steps.0.fallback_approval_role_id', null);

    // Round-trips through the detail endpoint.
    $this->getJson("/api/workflows/{$id}", ['Authorization' => 'Bearer ' . apiLogin('u1')])
        ->assertOk()
        ->assertJsonPath('data.steps.0.approver.full_name', 'Salma Ali Juma');
});

it('routes the request to the explicitly selected approver', function () {
    wasRetire('lt1');
    wasCreateWorkflow('department_head', 'e2');

    $token = apiLogin('u12'); // e12 sits in d1; selected approver is authoritative

    $requestId = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(40)->toDateString(),
        'end_date' => now()->addDays(44)->toDateString(),
        'reason' => 'Explicit approver routing check.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201)->json('data.id');

    $submitted = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    expect($submitted->json('workflow.0.assignee_id'))->toBe('e2')
        ->and($submitted->json('workflow.0.assignee_name'))->toBe('Salma Ali Juma')
        ->and($submitted->json('workflow.0.via'))->toBe('standing')
        ->and($submitted->json('workflow.0.selected_approver_id'))->toBe('e2');

    $assignment = WorkflowAssignment::where('request_id', $requestId)->firstOrFail();
    expect($assignment->assigned_employee_id)->toBe('e2')
        ->and($assignment->delegated_from_employee_id)->toBeNull()
        ->and($assignment->status)->toBe('pending');
});

it('routes to an active delegate while retaining the selected approver', function () {
    wasRetire('lt1');

    // Seeded del1: e6 (department head) delegates to e7 and is active today.
    Delegation::where('id', 'del1')->update(['active' => true]);

    wasCreateWorkflow('department_head', 'e6');

    $requestId = wasSubmitLeave('u12');

    $assignment = WorkflowAssignment::where('request_id', $requestId)->firstOrFail();
    expect($assignment->assigned_employee_id)->toBe('e7')
        ->and($assignment->delegated_from_employee_id)->toBe('e6');

    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u7')])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('approval_actions', [
        'actor_employee_id' => 'e7',
        'decision' => 'approved',
    ]);

    // The audit row keeps both the chosen approver and the acting delegate.
    $log = AuditLog::where('entity_type', 'leave_request')
        ->where('entity_id', (string) $requestId)
        ->where('action', 'approved')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($log->payload['selected_approver_id'])->toBe('e6')
        ->and($log->payload['selected_approver_name'])->toBe('Zaytuni Omar Juma')
        ->and($log->payload['acting_employee_id'])->toBe('e7')
        ->and($log->payload['acting_employee_name'])->toBe('Asha Hassan Haji')
        ->and($log->payload['delegated'])->toBeTrue();
});

it('blocks a request when the selected approver is the requester', function () {
    wasRetire('lt1');

    // e3 holds supervisor through three reporting lines and is also the
    // requester below, so submitting their own request must fail.
    wasCreateWorkflow('supervisor', 'e3');

    $token = apiLogin('u3');
    $id = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(50)->toDateString(),
        'end_date' => now()->addDays(53)->toDateString(),
        'reason' => 'Self-approval refusal check.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201)->json('data.id');

    $this->postJson("/api/requests/{$id}/submit", [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('workflow');

    expect(LeaveRequest::find($id)->status)->toBe('draft')
        ->and(WorkflowAssignment::where('request_id', $id)->count())->toBe(0);
});

it('rejects an approver who does not hold the selected role', function () {
    wasRetire('lt1');

    // e12 is a plain staff member, never an HR Reviewer.
    $invalid = $this->postJson('/api/workflows', [
        'workflow_title' => 'Invalid approver pair',
        'leave_type_id' => 'lt1',
        'strict_approvers' => true,
        'steps' => [[
            'approval_role_id' => 'hr_reviewer',
            'approver_employee_id' => 'e12',
            'scope_type' => 'office_wide',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => 'Bearer ' . apiLogin('u1')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('steps.0.approver_employee_id');

    expect($invalid->json('errors')['steps.0.approver_employee_id'][0])
        ->toBe('Haji Mwinyi Abdullah does not hold the HR Reviewer role.');

    // Strict mode also refuses a step with no approver at all.
    $missing = $this->postJson('/api/workflows', [
        'workflow_title' => 'Missing approver',
        'leave_type_id' => 'lt1',
        'strict_approvers' => true,
        'steps' => [[
            'approval_role_id' => 'department_head',
            'scope_type' => 'office_wide',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => 'Bearer ' . apiLogin('u1')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('steps.0.approver_employee_id');

    expect($missing->json('errors')['steps.0.approver_employee_id'][0])
        ->toBe('Select an approver for every step.');
});

it('refuses a second active workflow covering the same scope', function () {
    // lt2 keeps its seeded, active Sick Leave workflow.
    $this->postJson('/api/workflows', [
        'workflow_title' => 'Sick — duplicate active rule',
        'leave_type_id' => 'lt2',
        'strict_approvers' => true,
        'steps' => [[
            'approval_role_id' => 'department_head',
            'approver_employee_id' => 'e2',
            'scope_type' => 'office_wide',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => 'Bearer ' . apiLogin('u1')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('workflow');
});

it('records the selected approver, acting employee and decision in the audit trail', function () {
    wasRetire('lt1');
    wasCreateWorkflow('department_head', 'e2');

    $requestId = wasSubmitLeave('u12');

    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
        'comments' => 'Approved per staffing plan.',
    ], ['Authorization' => 'Bearer ' . apiLogin('u2')])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $log = AuditLog::where('entity_type', 'leave_request')
        ->where('entity_id', (string) $requestId)
        ->where('action', 'approved')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($log->payload['step_id'])->not->toBeNull()
        ->and($log->payload['step_order'])->toBe(1)
        ->and($log->payload['selected_approver_id'])->toBe('e2')
        ->and($log->payload['selected_approver_name'])->toBe('Salma Ali Juma')
        ->and($log->payload['acting_employee_id'])->toBe('e2')
        ->and($log->payload['acting_employee_name'])->toBe('Salma Ali Juma')
        ->and($log->payload['delegated'])->toBeFalse()
        ->and($log->payload['decision'])->toBe('approved')
        ->and($log->payload['comments'])->toBe('Approved per staffing plan.')
        ->and($log->payload['decided_at'])->not->toBeNull();
});
