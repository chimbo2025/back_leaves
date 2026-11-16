<?php

use App\Models\Delegation;
use App\Models\Employee;
use App\Models\EmployeeApprovalRole;
use App\Models\LeaveRequest;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Services\WorkflowResolver;
use Illuminate\Support\Facades\DB;

/**
 * Workflow Builder V2 + Workflow Management hardening (spec §1–§17).
 *
 * Covers the parts that are new or were previously unguarded: title
 * requirement, overlap prevention, duration bands, scope resolution,
 * assigned vs shared-queue actionability, delegation/fallback/self-exclusion,
 * aliases/permissions, and server-side pagination.
 */

function wbv2Retire(string $leaveTypeId): void
{
    DB::table('workflow_definitions')
        ->where('leave_type_id', $leaveTypeId)
        ->update(['is_active' => false]);
}

function wbv2Definition(array $attributes, array $steps): WorkflowDefinition
{
    $attributes = array_merge([
        'id' => 'wf_test_' . substr(md5(uniqid('', true)), 0, 10),
        'workflow_title' => 'Test Workflow',
        'min_duration_days' => 1,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_to' => null,
        'created_by' => 'u3',
        'is_active' => true,
    ], $attributes);

    if (! array_key_exists('version', $attributes)) {
        $attributes['version'] = ((int) WorkflowDefinition::where('leave_type_id', $attributes['leave_type_id'])->max('version')) + 1;
    }

    $definition = WorkflowDefinition::create($attributes);

    foreach ($steps as $i => $step) {
        WorkflowStep::create(array_merge([
            'workflow_def_id' => $definition->id,
            'step_order' => $i + 1,
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'requester_department',
            'sla_days' => 2,
            'is_mandatory' => true,
        ], $step));
    }

    return $definition->fresh(['steps.approvalRole']);
}

function wbv2Synthetic(string $employeeId, string $leaveTypeId, int $days = 5): LeaveRequest
{
    $employee = Employee::findOrFail($employeeId);

    $request = new LeaveRequest([
        'ref' => 'SYN-' . $employeeId . '-' . $leaveTypeId,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveTypeId,
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(30 + $days - 1)->toDateString(),
        'days' => $days,
        'status' => 'draft',
        'reason' => 'Resolution fixture',
    ]);
    $request->setRelation('employee', $employee);
    $request->submitted_at = now();

    return $request;
}

// ---------------------------------------------------------------------------
// Scope resolution
// ---------------------------------------------------------------------------

it('applies an office-wide workflow to every employee', function () {
    wbv2Retire('lt5');
    $definition = wbv2Definition(['leave_type_id' => 'lt5', 'workflow_title' => 'Office-wide emergency'], [
        ['approval_role_id' => 'supervisor', 'scope_type' => 'office_wide'],
    ]);

    $resolver = app(WorkflowResolver::class);

    foreach (['e7', 'e8', 'e12'] as $employeeId) {
        expect($resolver->resolveDefinition(wbv2Synthetic($employeeId, 'lt5'))?->id)
            ->toBe($definition->id);
    }
});

it('applies a zone workflow only to employees in that zone', function () {
    wbv2Retire('lt5');
    $definition = wbv2Definition([
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Unguja North emergency',
        'zone_id' => 'z2',
    ], [
        ['approval_role_id' => 'supervisor', 'scope_type' => 'office_wide'],
    ]);

    $resolver = app(WorkflowResolver::class);

    // e8 sits in z2; e7 sits in z1.
    expect($resolver->resolveDefinition(wbv2Synthetic('e8', 'lt5'))?->id)->toBe($definition->id)
        ->and($resolver->resolveDefinition(wbv2Synthetic('e7', 'lt5')))->toBeNull();
});

it('applies a department workflow only to employees in that department', function () {
    wbv2Retire('lt5');
    $definition = wbv2Definition([
        'leave_type_id' => 'lt5',
        'workflow_title' => 'Pemba emergency',
        'department_id' => 'd4',
    ], [
        ['approval_role_id' => 'supervisor', 'scope_type' => 'office_wide'],
    ]);

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveDefinition(wbv2Synthetic('e8', 'lt5'))?->id)->toBe($definition->id)
        ->and($resolver->resolveDefinition(wbv2Synthetic('e7', 'lt5')))->toBeNull();
});

it('honours non-overlapping duration bands (min and max)', function () {
    wbv2Retire('lt5');

    $short = wbv2Definition([
        'leave_type_id' => 'lt5', 'workflow_title' => 'Short emergency',
        'min_duration_days' => 1, 'max_duration_days' => 5,
    ], [['approval_role_id' => 'supervisor', 'scope_type' => 'office_wide']]);

    $long = wbv2Definition([
        'leave_type_id' => 'lt5', 'workflow_title' => 'Long emergency',
        'min_duration_days' => 6, 'max_duration_days' => null,
    ], [['approval_role_id' => 'supervisor', 'scope_type' => 'office_wide']]);

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveDefinition(wbv2Synthetic('e7', 'lt5', 3))?->id)->toBe($short->id)
        ->and($resolver->resolveDefinition(wbv2Synthetic('e7', 'lt5', 10))?->id)->toBe($long->id);
});

// ---------------------------------------------------------------------------
// Title + overlap validation
// ---------------------------------------------------------------------------

it('requires a workflow title', function () {
    $admin = apiLogin('u1');

    $this->postJson('/api/workflows', [
        'leave_type_id' => 'lt2',
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workflow_title']);
});

it('prevents an ambiguous active workflow for the same leave type, scope and duration', function () {
    $admin = apiLogin('u1');
    wbv2Retire('lt5');

    $payload = [
        'workflow_title' => 'Emergency — Entire office',
        'leave_type_id' => 'lt5',
        'min_duration_days' => 1,
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ];

    $this->postJson('/api/workflows', $payload, ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(201);

    $this->postJson('/api/workflows', array_merge($payload, ['workflow_title' => 'Emergency — duplicate']), ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workflow']);
});

it('allows the same chain for a different leave type', function () {
    $admin = apiLogin('u1');
    wbv2Retire('lt5');
    wbv2Retire('lt7');

    $steps = [['role_required' => 'HR', 'sla_days' => 2]];

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Emergency chain', 'leave_type_id' => 'lt5', 'steps' => $steps,
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Unpaid chain', 'leave_type_id' => 'lt7', 'steps' => $steps,
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);
});

it('allows duration bands but refuses overlapping ones', function () {
    $admin = apiLogin('u1');
    wbv2Retire('lt5');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Emergency 1-5 days', 'leave_type_id' => 'lt5',
        'min_duration_days' => 1, 'max_duration_days' => 5,
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Emergency 6+ days', 'leave_type_id' => 'lt5',
        'min_duration_days' => 6,
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Emergency 4-8 days', 'leave_type_id' => 'lt5',
        'min_duration_days' => 4, 'max_duration_days' => 8,
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['workflow']);
});

it('exposes the live builder check with scope, duration and conflict', function () {
    $admin = apiLogin('u1');
    wbv2Retire('lt5');

    $this->postJson('/api/workflows/check', [
        'leave_type_id' => 'lt5',
        'min_duration_days' => 1,
        'steps' => [['approval_role_id' => 'hr_reviewer']],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.conflict', false)
        ->assertJsonPath('data.steps.0.assignment_mode', 'shared_queue')
        ->assertJsonPath('data.steps.0.scope_type', 'office_wide');

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Emergency office chain', 'leave_type_id' => 'lt5',
        'steps' => [['role_required' => 'HR', 'sla_days' => 2]],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $this->postJson('/api/workflows/check', [
        'leave_type_id' => 'lt5',
        'steps' => [['approval_role_id' => 'hr_reviewer']],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.conflict', true);
});

// ---------------------------------------------------------------------------
// Role-based resolution, assigned approver vs shared queue
// ---------------------------------------------------------------------------

it('resolves the department head of the requester only, in assigned mode', function () {
    wbv2Retire('lt5');
    wbv2Definition(['leave_type_id' => 'lt5', 'workflow_title' => 'Department head assigned'], [
        ['approval_role_id' => 'department_head', 'assignment_mode' => 'assigned_approver', 'scope_type' => 'requester_department'],
    ]);

    $resolver = app(WorkflowResolver::class);

    $chain = $resolver->resolveWorkflow(wbv2Synthetic('e9', 'lt5')); // d5 → e5
    expect(collect($chain)->pluck('assignee_id')->all())->toBe(['e5']);
});

it('only lets the resolved approver action an assigned step', function () {
    $adminToken = apiLogin('u1');
    // e12 sits in d1 and carries no open court cases, so the final approval is
    // not paused by the case-handover gate.
    $requesterToken = apiLogin('u12');

    wbv2Retire('lt1');
    $this->postJson('/api/workflows', [
        'workflow_title' => 'Annual — Department head only',
        'leave_type_id' => 'lt1',
        'steps' => [[
            'approval_role_id' => 'department_head',
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'requester_department',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => "Bearer {$adminToken}"])->assertStatus(201);

    $requestId = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Assigned approver test.',
    ], ['Authorization' => "Bearer {$requesterToken}"])->assertStatus(201)->json('data.id');

    $submitted = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requesterToken}"])
        ->assertOk();

    expect(collect($submitted->json('workflow'))->pluck('assignee_id')->all())->toBe(['e3']);

    // Both inboxes exist; only the resolved head's contains the request.
    $headInbox = $this->getJson('/api/approvals/pending', ['Authorization' => 'Bearer ' . apiLogin('u3')])
        ->assertOk()->json('data');
    $otherHeadInbox = $this->getJson('/api/approvals/pending', ['Authorization' => 'Bearer ' . apiLogin('u2')])
        ->assertOk()->json('data');

    expect(collect($headInbox)->pluck('request_ref'))->toContain($submitted->json('data.ref'))
        ->and(collect($otherHeadInbox)->pluck('request_ref'))->not->toContain($submitted->json('data.ref'));

    // A different department head cannot jump in.
    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u2')])
        ->assertStatus(422);

    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u3')])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

it('lets every eligible holder see a shared queue and records only one final action', function () {
    $adminToken = apiLogin('u1');
    $requesterToken = apiLogin('u12');

    // Give a second employee the HR reviewer authority so the queue has two
    // eligible holders (the seed only ships one).
    EmployeeApprovalRole::create([
        'id' => 'ear_test_e2_hr',
        'employee_id' => 'e2',
        'approval_role_id' => 'hr_reviewer',
        'effective_from' => '2020-01-01',
        'is_primary' => false,
        'source' => 'manual_grant',
    ]);

    wbv2Retire('lt1');
    $this->postJson('/api/workflows', [
        'workflow_title' => 'Annual — HR shared queue',
        'leave_type_id' => 'lt1',
        'steps' => [[
            'approval_role_id' => 'hr_reviewer',
            'assignment_mode' => 'shared_queue',
            'scope_type' => 'office_wide',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => "Bearer {$adminToken}"])->assertStatus(201);

    $ref = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Shared queue test.',
    ], ['Authorization' => "Bearer {$requesterToken}"])->assertStatus(201)->json('data.ref');

    $requestId = DB::table('leave_requests')->where('ref', $ref)->value('id');
    $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requesterToken}"])->assertOk();

    foreach (['u3', 'u2'] as $username) {
        $inbox = $this->getJson('/api/approvals/pending', ['Authorization' => 'Bearer ' . apiLogin($username)])
            ->assertOk()->json('data');
        expect(collect($inbox)->pluck('request_ref'))->toContain($ref);
    }

    // First HR reviewer decides; the acting user is the one recorded.
    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u2')])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('approval_actions', [
        'actor_employee_id' => 'e2',
        'decision' => 'approved',
    ]);

    // A second reviewer cannot approve again.
    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u3')])
        ->assertStatus(422);

    $assignmentIds = DB::table('workflow_assignments')->where('request_id', $requestId)->pluck('id');
    expect(DB::table('approval_actions')->where('actor_employee_id', 'e3')->whereIn('assignment_id', $assignmentIds)->count())->toBe(0);
});

it('routes to an active delegate and refuses an expired one', function () {
    $adminToken = apiLogin('u1');

    wbv2Retire('lt1');

    // Active delegation: e3 (head of d1) → e4.
    Delegation::create([
        'id' => 'del_test_active', 'delegator_employee_id' => 'e3', 'delegate_employee_id' => 'e4',
        'role_id' => 'DEPT_HEAD', 'approval_role_id' => 'department_head',
        'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
        'authorized_by' => 'e1', 'active' => true,
    ]);

    $this->postJson('/api/workflows', [
        'workflow_title' => 'Annual — delegated department head',
        'leave_type_id' => 'lt1',
        'steps' => [[
            'approval_role_id' => 'department_head',
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'requester_department',
            'sla_days' => 2,
        ]],
    ], ['Authorization' => "Bearer {$adminToken}"])->assertStatus(201);

    // e12 sits in d1 (head = e3).
    $requesterToken = apiLogin('u12');
    $requestId = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Delegation test.',
    ], ['Authorization' => "Bearer {$requesterToken}"])->assertStatus(201)->json('data.id');

    $submitted = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requesterToken}"])
        ->assertOk();

    expect(collect($submitted->json('workflow'))->pluck('assignee_id')->all())->toBe(['e4'])
        ->and($submitted->json('workflow.0.via'))->toBe('delegation');

    $this->postJson("/api/approvals/{$requestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u4')])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('approval_actions', [
        'actor_employee_id' => 'e4',
        'decision' => 'approved',
    ]);

    // Expired delegation: same shape, but outside its window.
    Delegation::where('id', 'del_test_active')->update([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->subMonth()->toDateString(),
    ]);

    $expiredRequestId = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(40)->toDateString(),
        'end_date' => now()->addDays(44)->toDateString(),
        'reason' => 'Expired delegation test.',
    ], ['Authorization' => "Bearer {$requesterToken}"])->assertStatus(201)->json('data.id');

    $expiredChain = $this->postJson("/api/requests/{$expiredRequestId}/submit", [], ['Authorization' => "Bearer {$requesterToken}"])
        ->assertOk();

    expect(collect($expiredChain->json('workflow'))->pluck('assignee_id')->all())->toBe(['e3']);

    $this->postJson("/api/approvals/{$expiredRequestId}/approve", [
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer ' . apiLogin('u4')])
        ->assertStatus(422);
});

it('falls back to the fallback role when the primary role has no holder', function () {
    wbv2Retire('lt5');
    // No standing department-head delegation may stand in for the missing
    // primary holder, or the fallback path is never exercised.
    Delegation::where('id', 'del1')->update(['active' => false]);

    // Strip the d1/d6 department-head grants so e3 only stands in the role
    // through her reporting-line supervisor authority.
    DB::table('employee_approval_roles')
        ->where('approval_role_id', 'department_head')
        ->whereIn('organizational_unit_id', ['d1', 'd6'])
        ->delete();

    wbv2Definition(['leave_type_id' => 'lt5', 'workflow_title' => 'Fallback to supervisor'], [
        [
            'approval_role_id' => 'department_head',
            'fallback_approval_role_id' => 'supervisor',
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'requester_department',
        ],
    ]);

    $resolver = app(WorkflowResolver::class);
    $chain = $resolver->resolveWorkflow(wbv2Synthetic('e10', 'lt5')); // d6 → reporting line e3

    expect(collect($chain)->pluck('assignee_id')->all())->toBe(['e3']);
});

it('leaves the request unresolved when neither primary nor fallback has a holder', function () {
    wbv2Retire('lt5');
    Delegation::where('id', 'del1')->update(['active' => false]);

    DB::table('employee_approval_roles')
        ->where('approval_role_id', 'department_head')
        ->whereIn('organizational_unit_id', ['d1', 'd6'])
        ->delete();

    wbv2Definition(['leave_type_id' => 'lt5', 'workflow_title' => 'Unstaffed'], [
        [
            'approval_role_id' => 'department_head',
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'requester_department',
            'is_mandatory' => true,
        ],
    ]);

    $resolver = app(WorkflowResolver::class);

    expect($resolver->resolveWorkflow(wbv2Synthetic('e10', 'lt5')))->toBe([]);
});

it('never lets a requester approve their own step (self-exclusion)', function () {
    wbv2Retire('lt5');

    wbv2Definition(['leave_type_id' => 'lt5', 'workflow_title' => 'Self-exclusion'], [
        [
            'approval_role_id' => 'department_head',
            'assignment_mode' => 'assigned_approver',
            'scope_type' => 'requester_department',
        ],
    ]);

    $resolver = app(WorkflowResolver::class);
    $chain = $resolver->resolveWorkflow(wbv2Synthetic('e6', 'lt5')); // requester is d2's head

    expect(collect($chain)->pluck('assignee_id')->all())->not->toContain('e6')
        ->and(collect($chain)->pluck('assignee_id')->all())->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Alias endpoints, permissions, pagination
// ---------------------------------------------------------------------------

it('serves the spec aliases', function () {
    $admin = apiLogin('u1');

    $this->getJson('/api/approval-workflows', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson('/api/approvals/pending', ['Authorization' => "Bearer {$admin}"])->assertOk();
    $this->getJson('/api/role-assignments', ['Authorization' => "Bearer {$admin}"])->assertOk();
});

it('denies workflow and role-assignment writes without the codes', function () {
    $supervisor = apiLogin('u7');

    $this->postJson('/api/approval-workflows', [], ['Authorization' => "Bearer {$supervisor}"])->assertForbidden();
    $this->patchJson('/api/approval-workflows/wf_annual', ['is_active' => false], ['Authorization' => "Bearer {$supervisor}"])->assertForbidden();
    $this->getJson('/api/role-assignments', ['Authorization' => "Bearer {$supervisor}"])->assertForbidden();
});

it('paginates, filters, searches and sorts the workflow list server-side', function () {
    $admin = apiLogin('u1');

    $page = $this->getJson('/api/workflows?page=1&per_page=2', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'last_page', 'total', 'from', 'to']);

    expect($page->json('per_page'))->toBe(2)
        ->and($page->json('current_page'))->toBe(1)
        ->and($page->json('data'))->toHaveCount(2)
        ->and($page->json('total'))->toBeGreaterThan(2)
        ->and($page->json('last_page'))->toBeGreaterThan(1);

    $search = $this->getJson('/api/workflows?page=1&per_page=25&search=Sick', ['Authorization' => "Bearer {$admin}"])
        ->assertOk();
    collect($search->json('data'))->each(fn ($row) => expect($row['workflow_title'])->toContain('Sick'));

    $filtered = $this->getJson('/api/workflows?page=1&per_page=25&leave_type_id=lt2&status=active', ['Authorization' => "Bearer {$admin}"])
        ->assertOk();
    expect(collect($filtered->json('data'))->pluck('leave_type_id')->unique()->all())->toBe(['lt2']);
    collect($filtered->json('data'))->each(fn ($row) => expect($row['is_active'])->toBeTrue());

    $sorted = $this->getJson('/api/workflows?page=1&per_page=25&sort=title&direction=asc', ['Authorization' => "Bearer {$admin}"])
        ->assertOk();
    $titles = collect($sorted->json('data'))->pluck('workflow_title')->all();
    expect($titles)->toBe(collect($titles)->sort()->values()->all());

    // Backward-compatible unpaginated shape is still exactly {data: [...]}.
    $legacy = $this->getJson('/api/workflows', ['Authorization' => "Bearer {$admin}"])->assertOk();
    expect($legacy->json())->not->toHaveKey('current_page')
        ->and($legacy->json('data'))->toBeArray();
});

it('shows the workflow title in list and detail responses', function () {
    $admin = apiLogin('u1');

    $this->getJson('/api/workflows/wf_annual', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.workflow_title', 'Annual Leave Workflow v1');

    $list = $this->getJson('/api/workflows?search=Annual', ['Authorization' => "Bearer {$admin}"])->assertOk()->json('data');
    expect(collect($list)->pluck('workflow_title'))->toContain('Annual Leave Workflow v1');
});
