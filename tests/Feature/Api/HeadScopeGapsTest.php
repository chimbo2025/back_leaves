<?php

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\WorkflowAssignment;

/**
 * Scope honesty around heads (follow-up to the head-appointment feature):
 *
 *  1. The eligibility org summary claimed "scoped => true" while returning
 *     ORGANISATION-WIDE totals to any caller without a department grant —
 *     the empty-department branch read as "all departments".
 *  2. The assignment inbox is deliberately unscoped (assignment beats record
 *     scope), but nothing said so; each row now carries out_of_scope.
 *  3. DEPT_HEAD/ZONAL_HEAD minted without a unit resolve to own-records-only
 *     ("head of nowhere"), so every unscoped mint path must refuse them.
 */
it('scopes the eligibility org summary to the caller grants and own record', function () {
    $activeTotal = Employee::where('is_active', true)->count();

    // Department-only head (u2, DEPT_HEAD of d3 — nobody else in d3; her own
    // record is retained separately, so the total is grant + self).
    $deptOrg = $this->getJson('/api/eligibility', ['Authorization' => 'Bearer '.apiLogin('u2')])
        ->assertOk()
        ->json('org');

    $d3Total = Employee::where('is_active', true)
        ->where(fn ($q) => $q->where('department_id', 'd3')->orWhere('id', 'e2'))
        ->count();
    expect($deptOrg['scoped'])->toBeTrue()
        ->and($deptOrg['total_employees'])->toBe($d3Total)
        ->and($deptOrg['total_employees'])->toBeLessThan($activeTotal)
        ->and(collect($deptOrg['per_department'])->pluck('department_id')->all())
        ->toContain(Employee::find('e2')?->department_id);

    // Zone + department head (u4, ZONAL_HEAD z2 + DEPT_HEAD d4): the union,
    // plus her own record.
    $zoneOrg = $this->getJson('/api/eligibility', ['Authorization' => 'Bearer '.apiLogin('u4')])
        ->assertOk()
        ->json('org');

    $expected = Employee::where('is_active', true)
        ->where(fn ($q) => $q->where('zone_id', 'z2')->orWhere('department_id', 'd4')->orWhere('id', 'e4'))
        ->count();
    expect($zoneOrg['scoped'])->toBeTrue()
        ->and($zoneOrg['total_employees'])->toBe($expected)
        ->and($zoneOrg['total_employees'])->toBeLessThan($activeTotal)
        ->and(collect($zoneOrg['per_zone'])->pluck('zone_id')->all())->toContain('z2');

    // Own-records-only caller (u7, SUPERVISOR without scope grants) sees
    // exactly themselves — not the organisation.
    $ownOrg = $this->getJson('/api/eligibility', ['Authorization' => 'Bearer '.apiLogin('u7')])
        ->assertOk()
        ->json('org');

    expect($ownOrg['scoped'])->toBeTrue()
        ->and($ownOrg['total_employees'])->toBe(1)
        ->and($ownOrg['total_eligible'])->toBeLessThanOrEqual(1);
});

it('flags inbox rows whose requester sits outside the caller scope', function () {
    // u4 = zonal head of z2 (plus DEPT_HEAD of d4).
    $northRequest = LeaveRequest::where('ref', 'LVE-2026-0150')->firstOrFail(); // e8, z2
    $hqRequest = LeaveRequest::where('ref', 'LVE-2026-0147')->firstOrFail();    // e7, z1

    WorkflowAssignment::create([
        'request_id' => $northRequest->id,
        'assigned_employee_id' => 'e4',
        'assigned_role_id' => 'ZONAL_HEAD',
        'status' => 'pending',
        'due_at' => now()->addDays(3),
    ]);
    WorkflowAssignment::create([
        'request_id' => $hqRequest->id,
        'assigned_employee_id' => 'e4',
        'assigned_role_id' => 'ZONAL_HEAD',
        'status' => 'pending',
        'due_at' => now()->addDays(4),
    ]);

    $rows = collect($this->getJson('/api/requests/inbox', ['Authorization' => 'Bearer '.apiLogin('u4')])
        ->assertOk()
        ->json('data'));

    $inScope = $rows->firstWhere('request_ref', 'LVE-2026-0150');
    $crossScope = $rows->firstWhere('request_ref', 'LVE-2026-0147');

    expect($inScope['out_of_scope'])->toBeFalse()
        ->and($crossScope['out_of_scope'])->toBeTrue();

    // The DPP (org-wide) never sees the flag raised.
    WorkflowAssignment::create([
        'request_id' => $hqRequest->id,
        'assigned_employee_id' => 'e1',
        'assigned_role_id' => 'FINAL_AUTHORITY',
        'status' => 'pending',
        'due_at' => now()->addDays(5),
    ]);

    $dppRows = collect($this->getJson('/api/requests/inbox', ['Authorization' => 'Bearer '.apiLogin('u1')])
        ->assertOk()
        ->json('data'));

    expect($dppRows->firstWhere('request_ref', 'LVE-2026-0147')['out_of_scope'])->toBeFalse();
});

it('refuses to mint an unscoped department or zonal head through any role path', function () {
    $admin = ['Authorization' => 'Bearer '.apiLogin('u1')];

    // Registration.
    $this->postJson('/api/org/employees', [
        'username' => 'head.mint', 'password' => 'secret123', 'full_name' => 'Head Mint',
        'position_id' => 'p2', 'department_id' => 'd1', 'zone_id' => 'z1',
        'hire_date' => '2026-01-01', 'role_ids' => ['DEPT_HEAD'],
    ], $admin)->assertStatus(422)->assertJsonPath('errors.role_ids.0', 'Department and zonal heads are appointed to a specific unit. Use the department/zone head picker, not the role list.');

    expect(Employee::where('full_name', 'Head Mint')->exists())->toBeFalse();

    // Profile replacement (e8 holds no scoped DEPT_HEAD, so the id would mint).
    $this->patchJson('/api/org/employees/e8', ['role_ids' => ['ZONAL_HEAD']], $admin)
        ->assertStatus(422);
    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'ZONAL_HEAD')->exists())->toBeFalse();

    // One-off grant endpoint.
    $this->postJson('/api/org/employees/e8/roles/DEPT_HEAD', [], $admin)
        ->assertStatus(422);

    // Position defaults.
    $this->patchJson('/api/org/positions/p2', ['default_role_ids' => ['DEPT_HEAD', 'SUPERVISOR']], $admin)
        ->assertStatus(422);
    $this->postJson('/api/org/positions', [
        'title' => 'Head Magnet', 'department_id' => 'd1', 'default_role_ids' => ['ZONAL_HEAD'],
    ], $admin)->assertStatus(422);

    // Non-structural grants still flow.
    $this->patchJson('/api/org/employees/e8', ['role_ids' => ['SUPERVISOR']], $admin)
        ->assertOk();
});

it('removes a legacy unscoped head grant through the profile once the picker refuses it', function () {
    $admin = ['Authorization' => 'Bearer '.apiLogin('u1')];

    // Recreate the e7-style drift: DEPT_HEAD with no unit (own-records-only).
    EmployeeRole::create([
        'employee_id' => 'e8', 'role_id' => 'DEPT_HEAD',
        'source' => 'manual_grant', 'granted_by' => 'u1', 'effective_from' => '2026-01-01',
    ]);

    // Re-saving WITH the id in the payload is refused (no silent re-create)…
    $this->patchJson('/api/org/employees/e8', ['role_ids' => ['DEPT_HEAD']], $admin)
        ->assertStatus(422);
    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'DEPT_HEAD')->exists())->toBeTrue();

    // …but unchecking it (omitting the id) still drops the drift row.
    $this->patchJson('/api/org/employees/e8', ['role_ids' => ['SUPERVISOR']], $admin)
        ->assertOk();
    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'DEPT_HEAD')->exists())->toBeFalse();
});
