<?php

use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\WorkflowDefinition;
use App\Services\PermissionService;
use Carbon\Carbon;

/**
 * Parity tests for the 5 admin/config write-endpoints that the frontend
 * mock exposes but the backend previously lacked:
 *   1. draft editing:        PATCH /requests/{leaveRequest}
 *   2. employee config:      PATCH /org/employees/{employee}
 *   3. leave-type config:    POST + PATCH /leave/types
 *   4. workflow editing:     POST + PATCH /workflows
 *   5. roles/permissions:    GET + POST /roles, PATCH + DELETE /roles/{role}
 */
it('edits a draft request before submission and recomputes days', function () {
    $admin = apiLogin('u1');

    $draft = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-05',
        'reason' => 'Initial draft',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    expect($draft['days'])->toBe(5);
    expect(LeaveRequest::find($draft['id'])->status)->toBe('draft');

    $this->patchJson("/api/requests/{$draft['id']}", [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-12',
        'reason' => 'Extended before submitting',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.days', 12)
        ->assertJsonPath('data.reason', 'Extended before submitting');

    expect(LeaveRequest::find($draft['id'])->status)->toBe('draft');
});

it('only the requester may edit a draft, and only while it is a draft', function () {
    $admin = apiLogin('u1');
    $draft = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-03',
    ], ['Authorization' => "Bearer {$admin}"])->json('data');

    // Another employee (supervisor u7) cannot edit someone else's draft.
    $supervisor = apiLogin('u7');
    $this->patchJson("/api/requests/{$draft['id']}", [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-04',
    ], ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);

    // A submitted seed request (r8, employee e8) is not editable — 422.
    $r8 = LeaveRequest::where('ref', 'LVE-2026-0162')->value('id');
    $owner8 = apiLogin('u8');
    $this->patchJson("/api/requests/{$r8}", [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-09',
        'end_date' => '2026-11-20',
    ], ['Authorization' => "Bearer {$owner8}"])->assertStatus(422);
});

it('patches employee fields behind admin.update and records the change in the ledger', function () {
    $admin = apiLogin('u1');

    $this->patchJson('/api/org/employees/e10', ['full_name' => 'Baraka Said Maulid II'], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Baraka Said Maulid II');

    // Invalid reference data rejected.
    $this->patchJson('/api/org/employees/e10', ['position_id' => 'bogus'], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422);

    // Non-admins have no admin.update.
    $supervisor = apiLogin('u7');
    $this->patchJson('/api/org/employees/e10', ['full_name' => 'X'], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);

    $this->getJson('/api/audit/verify', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.valid', true);
});

it('creates and updates leave types behind admin gates', function () {
    $hr = apiLogin('u3');

    $created = $this->postJson('/api/leave/types', [
        'code' => 'CIVIC',
        'name' => 'Community Service Leave',
        'swahili' => 'Likizo ya Huduma ya Jamii',
        'short' => 'Jamii',
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201)->json('data');

    expect($created['id'])->toBe('lt8');
    expect(LeaveType::find('lt8')->exists())->toBeTrue();

    $this->patchJson('/api/leave/types/lt8', ['name' => 'Community Leave'], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.name', 'Community Leave');

    // Duplicate code rejected.
    $this->postJson('/api/leave/types', ['code' => 'ANNUAL', 'name' => 'Dup'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);

    // Non-admins have no admin.create / admin.update.
    $supervisor = apiLogin('u7');
    $this->postJson('/api/leave/types', ['code' => 'X', 'name' => 'Y'], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);
    $this->patchJson('/api/leave/types/lt1', ['name' => 'Nope'], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);
});

it('creates and patches workflow definitions behind the edit_policy gate', function () {
    $hr = apiLogin('u3');

    $version = WorkflowDefinition::where('leave_type_id', 'lt1')->max('version') + 1;
    $id = "wf_lt1_v{$version}";

    $created = $this->postJson('/api/workflows', [
        'leave_type_id' => 'lt1',
        'steps' => [
            ['role_required' => 'SUPERVISOR', 'sla_days' => 2, 'note' => 'First review.'],
            ['role_required' => 'HR', 'sla_days' => 3, 'note' => 'Compliance check.'],
        ],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201)->json('data');

    expect($created['id'])->toBe($id);
    expect($created['version'])->toBe($version);
    expect($created['steps'])->toHaveCount(2);

    $this->patchJson("/api/workflows/{$id}", [
        'steps' => [['role_required' => 'SUPERVISOR', 'sla_days' => 1, 'note' => 'Revised.']],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.version', $version + 1)
        ->assertJsonCount(1, 'data.steps');

    // Invalid step role rejected.
    $this->postJson('/api/workflows', [
        'leave_type_id' => 'lt1',
        'steps' => [['role_required' => 'NOPE', 'sla_days' => 1]],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(422);

    // Supervisors can view workflows (policies.view) but not edit (no edit_policy).
    $supervisor = apiLogin('u7');
    $this->getJson('/api/workflows', ['Authorization' => "Bearer {$supervisor}"])->assertOk();
    $this->postJson('/api/workflows', ['leave_type_id' => 'lt1', 'steps' => []], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);

    expect(WorkflowDefinition::count())->toBe(8); // 7 seeded + 1 created
});

it('manages roles and permission sets behind admin gates, protecting system roles', function () {
    $hr = apiLogin('u3');
    $admin = apiLogin('u1');

    $this->getJson('/api/roles', ['Authorization' => "Bearer {$admin}"])->assertOk();

    // Non-admins cannot read or touch roles.
    $supervisor = apiLogin('u7');
    $this->getJson('/api/roles', ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);

    $created = $this->postJson('/api/roles', [
        'name' => 'Records Manager',
        'permissions' => ['requests.view', 'reports.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201)->json('data');

    expect($created['id'])->toBe('ROLE_RECORDS_MANAGER');
    expect($created['permissions'])->toHaveCount(2);

    $this->patchJson('/api/roles/ROLE_RECORDS_MANAGER', [
        'permissions' => ['requests.view'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonCount(1, 'data.permissions');

    // The final-authority flag is immutable, so a role cannot be minted into an
    // org-wide superuser through the API — which, while the flag fed the `['*']`
    // sentinel, took only admin.create.
    $this->patchJson('/api/roles/FINAL_AUTHORITY', ['is_final_authority' => false], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);

    $this->patchJson('/api/roles/ROLE_RECORDS_MANAGER', ['is_final_authority' => true], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);

    expect(Role::find('ROLE_RECORDS_MANAGER')->is_final_authority)->toBeFalse();

    $this->postJson('/api/roles', [
        'name' => 'Shadow Admin',
        'is_final_authority' => true,
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(422);

    expect(Role::where('name', 'Shadow Admin')->exists())->toBeFalse();

    // Unknown permission codes rejected on store.
    $this->postJson('/api/roles', ['name' => 'Bad Role', 'permissions' => ['nope.view']], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);

    // A break-glass grant is bounded, so it cannot become a standing privilege
    // through either assignment path. FINAL_AUTHORITY (u1) holds every code,
    // so it can grant BREAK_GLASS; HR (u3) does not hold leave_payments.*.
    $dpp = apiLogin('u1');
    $this->postJson('/api/org/employees/e8/roles/BREAK_GLASS', [], ['Authorization' => "Bearer {$dpp}"])
        ->assertOk();

    $breakGlass = EmployeeRole::where('employee_id', 'e8')->where('role_id', 'BREAK_GLASS')->firstOrFail();

    // Bounded to the configured cap, and never unbounded.
    //
    // Asserted as two separate properties on purpose. The previous version took
    // abs() of a signed diffInDays to sidestep Carbon's sign convention, which
    // also made an ALREADY EXPIRED grant pass - the exact opposite of what this
    // guard exists to catch. Direction is now asserted directly, and the
    // absolute magnitude only measures the cap.
    $this->assertNotNull(
        $breakGlass->effective_to,
        'A break-glass grant must never be left unbounded.'
    );

    $expiresAt = Carbon::parse($breakGlass->effective_to);
    $this->assertTrue(
        $expiresAt->isFuture(),
        "Break-glass expiry {$breakGlass->effective_to} is not in the future."
    );
    $this->assertLessThanOrEqual(
        PermissionService::BREAK_GLASS_MAX_DAYS,
        (int) now()->diffInDays($expiresAt, true)
    );

    $this->getJson('/api/audit/verify', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.valid', true);
});
