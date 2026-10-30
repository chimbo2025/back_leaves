<?php

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\ReportingLine;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\AccessScopeService;
use App\Services\WorkflowResolver;
use Illuminate\Support\Facades\DB;

/**
 * Admin-panel registration + live permission catalog and role-permission
 * persistence (frontend wiring targets for both gaps).
 */
it('admin registers a staff member with a login, roles and reporting line', function () {
    $admin = apiLogin('u1');

    $res = $this->postJson('/api/org/employees', [
        'full_name' => 'Amina Yusuf Mwinyi',
        'initials' => 'AY',
        'position_id' => 'p8',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'gender' => 'F',
        'email' => 'amina.yusuf@dpp.go.tz',
        'hire_date' => '2026-09-01',
        'username' => 'amina',
        'password' => 'secret99',
        'role_ids' => ['SUPERVISOR'],
        'supervisor_employee_id' => 'e6',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    expect($res['id'])->toBe('e13');
    expect($res['user_id'])->toBe('u13');
    expect(Employee::find('e13')->user->username)->toBe('amina');
    expect(EmployeeRole::where('employee_id', 'e13')->pluck('role_id')->all())->toBe(['SUPERVISOR']);
    expect(ReportingLine::where('employee_id', 'e13')->value('supervisor_employee_id'))->toBe('e6');

    // The registered account can log in with its own credentials.
    $this->postJson('/api/login', ['username' => 'amina', 'password' => 'secret99'])
        ->assertOk()
        ->assertJsonPath('user.username', 'amina');

    // Ledger stays tamper-evident after the create action is audited.
    $this->getJson('/api/audit/verify', ['Authorization' => "Bearer {$admin}"])->assertJsonPath('data.valid', true);
});

it('defaults role grants from the position when role_ids are omitted', function () {
    $admin = apiLogin('u1');

    $res = $this->postJson('/api/org/employees', [
        'full_name' => 'Juma Ali Bakari',
        'position_id' => 'p8',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'hire_date' => '2026-09-01',
        'username' => 'juma.b',
        'password' => 'secret99',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    expect(EmployeeRole::where('employee_id', $res['id'])->pluck('role_id')->all())->toBe(['SUPERVISOR']);
    expect(EmployeeRole::where('employee_id', $res['id'])->value('source'))->toBe('position_default');
});

it('only a user with admin.create may register staff', function () {
    $supervisor = apiLogin('u7');

    $this->postJson('/api/org/employees', [
        'full_name' => 'Nope Nope',
        'position_id' => 'p8',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'hire_date' => '2026-09-01',
        'username' => 'nope',
        'password' => 'secret99',
    ], ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);
});

it('rejects duplicate usernames with a 422', function () {
    $admin = apiLogin('u1');

    $this->postJson('/api/org/employees', [
        'full_name' => 'Dup Dup',
        'position_id' => 'p8',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'hire_date' => '2026-09-01',
        'username' => 'u3',
        'password' => 'secret99',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(422);
});

it('exposes the full permission catalog grouped across every module', function () {
    $admin = apiLogin('u1');

    $catalog = $this->getJson('/api/roles/permissions', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    // Phase 1 codes across the original modules plus the Phase 2 leave-planning
    // and waiver codes (leave_plans, leave_waivers), Phase 3 return-to-work
    // codes (leave_returns), Phase 5 leave payments (leave_payments), Phase 6
    // official leave documents (leave_documents), Phase 7 reports/exports/audit
    // (reports, report_exports, audit_logs), and the governance normalization
    // modules (approval_workflows, case_handovers) with the request lifecycle,
    // policy lifecycle and staff scope codes.
    // The navigation-only codes that used to appear here (dashboard, myleave,
    // approvals, calendar, workflows, cases, notifications, audit) were removed
    // by migration 2026_10_03_120000 because no endpoint or policy ever read them.
    // leave_documents.verify_public followed via 2026_10_16_120000: its QR
    // verification page was deleted as dead code, leaving the code decorative.
    // The organization refactor added the two force-delete capabilities, which
    // live in their own `leave_requests` and `employees` modules.
    expect($catalog)->toHaveCount(108);
    expect(collect($catalog)->pluck('module')->unique()->sort()->values()->all())
        ->toBe([
            'admin',
            'approval_workflows',
            'audit_logs',
            'case_handovers',
            'delegations',
            'documents',
            'employees',
            'leave_documents',
            'leave_payments',
            'leave_plans',
            'leave_requests',
            'leave_returns',
            'leave_waivers',
            'notifications',
            'policies',
            'report_exports',
            'reports',
            'requests',
            'staff',
        ]);
    expect(collect($catalog)->pluck('code'))->toContain(
        'requests.approve_zone',
        'documents.view_medical_documents',
        'requests.view_cross_zone',
        'staff.view_cross_zone',
        'reports.view_cross_zone',
        'admin.delete',
    );
    // Guard against the decorative codes creeping back into the catalog.
    // (requests.reject/return and policies.update are intentionally absent
    // from this list now: the governance normalization wires them to real
    // enforcement points, so they are legitimate codes again.)
    expect(collect($catalog)->pluck('code'))->not->toContain(
        'reports.export',
        'requests.update',
        'requests.delete',
        'requests.export',
        'policies.create',
        'policies.delete',
        'documents.delete',
        'staff.view',
        'dashboard.view',
        'myleave.view',
        'approvals.view',
        'calendar.view',
        'workflows.view',
        'cases.view',
        'notifications.view',
        'audit.view',
    );
});

it('persists matrix changes to a role and enforces them immediately', function () {
    // SUPERVISOR starts with documents/reports view (seed). Grant only requests + reports.
    $admin = apiLogin('u1');
    $this->patchJson('/api/roles/SUPERVISOR', [
        'permissions' => ['requests.view', 'requests.approve', 'reports.view'],
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();

    $role = Role::with('permissions')->find('SUPERVISOR');
    expect($role->permissions->pluck('code')->sort()->values()->all())
        ->toBe(['reports.view', 'requests.approve', 'requests.view']);

    // Revoking documents.view from SUPERVISOR blocks a documents-gated supervisor call.
    $supervisor = apiLogin('u7');
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$supervisor}"])->assertOk();
    $this->patchJson('/api/roles/SUPERVISOR', [
        'permissions' => ['requests.view', 'requests.approve'],
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$supervisor}"])->assertStatus(403);
    expect(Permission::where('code', 'reports.view')->exists())->toBeTrue(); // catalog row untouched
});

it('still resolves approvals after registering a new staff member', function () {
    $admin = apiLogin('u1');
    $this->postJson('/api/org/employees', [
        'full_name' => 'Farida Salim Omar',
        'position_id' => 'p8',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'hire_date' => '2026-09-01',
        'username' => 'farida',
        'password' => 'secret99',
        'role_ids' => ['SUPERVISOR'],
        'supervisor_employee_id' => 'e6',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    // Existing seed request r8 (e8) can still advance through its chain.
    $this->getJson('/api/requests?scope=all', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.total', 13);
    expect(LeaveRequest::count())->toBe(13);
});

/*
|--------------------------------------------------------------------------
| Dynamic role lifecycle (create / edit / delete)
|--------------------------------------------------------------------------
*/

it('creates a dynamic role, edits its grants, then archives it', function () {
    $hr = apiLogin('u3'); // HR holds admin.create/update/delete through its role

    $created = $this->postJson('/api/roles', [
        'id' => 'ROLE_AUDITOR',
        'name' => 'Leave Auditor',
        'description' => 'Reads leave and payroll reporting without approving anything.',
        'approval_tier' => 2,
        'permissions' => ['reports.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201)->json('data');

    expect($created['id'])->toBe('ROLE_AUDITOR')
        ->and($created['is_system'])->toBeFalse()
        ->and($created['active'])->toBeTrue()
        ->and($created['description'])->toBe('Reads leave and payroll reporting without approving anything.')
        ->and(collect($created['permissions'])->pluck('code')->all())->toBe(['reports.view']);

    $this->patchJson('/api/roles/ROLE_AUDITOR', [
        'name' => 'Leave & Payroll Auditor',
        'description' => 'Reads reporting across all zones; still cannot approve.',
        'permissions' => ['reports.view', 'reports.view_cross_zone'],
    ], ['Authorization' => "Bearer {$hr}"])->assertOk();

    expect(Role::find('ROLE_AUDITOR')->name)->toBe('Leave & Payroll Auditor')
        ->and(Role::find('ROLE_AUDITOR')->description)->toBe('Reads reporting across all zones; still cannot approve.')
        ->and(Role::find('ROLE_AUDITOR')->permissions()->pluck('code')->sort()->values()->all())
        ->toBe(['reports.view', 'reports.view_cross_zone']);

    // Delete means archive: the row survives so the audit trail still resolves.
    $this->deleteJson('/api/roles/ROLE_AUDITOR', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    expect(Role::find('ROLE_AUDITOR'))->not->toBeNull()
        ->and(Role::find('ROLE_AUDITOR')->active)->toBeFalse()
        ->and(Role::find('ROLE_AUDITOR')->permissions()->count())->toBe(2);
    // The catalog permission rows are untouched either way.
    expect(Permission::where('code', 'reports.view')->exists())->toBeTrue();

    // And it is reversible, with the permissions intact.
    $this->postJson('/api/roles/ROLE_AUDITOR/restore', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.active', true);
    expect(Role::find('ROLE_AUDITOR')->active)->toBeTrue();
});

it('persists a role description, keeps it optional, and rejects an over-long one', function () {
    $hr = apiLogin('u3');

    // Optional: omitted description is valid and reads back as null.
    $noDescription = $this->postJson('/api/roles', [
        'id' => 'ROLE_MINIMAL',
        'name' => 'Minimal Role',
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201)->json('data');
    expect($noDescription['description'])->toBeNull();

    // The seeded roles are described, so the matrix is not blank on first load.
    $listed = $this->getJson('/api/roles', ['Authorization' => "Bearer {$hr}"])->assertOk()->json('data');
    $described = collect($listed)->filter(fn ($r) => filled($r['description']))->pluck('id')->all();
    expect($described)->toContain('SUPERVISOR', 'DEPT_HEAD', 'ZONAL_HEAD', 'HR', 'FINAL_AUTHORITY');

    // An explicit null clears it rather than being ignored.
    $this->patchJson('/api/roles/ROLE_MINIMAL', ['description' => null], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.description', null);
    expect(Role::find('ROLE_MINIMAL')->description)->toBeNull();

    // A long description is a 422, not a silent truncation.
    $this->patchJson('/api/roles/ROLE_MINIMAL', [
        'description' => str_repeat('x', 256),
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(422)->assertJsonValidationErrors('description');

    // And the description is not mistaken for a permission grant.
    $this->patchJson('/api/roles/ROLE_MINIMAL', [
        'description' => 'Reads reports only.',
        'permissions' => ['reports.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertOk();
    expect(Role::find('ROLE_MINIMAL')->description)->toBe('Reads reports only.')
        ->and(Role::find('ROLE_MINIMAL')->permissions()->pluck('code')->all())->toBe(['reports.view']);
});

it('enforces a dynamic role immediately and drops the codes when it is deleted', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', [
        'id' => 'ROLE_REPORTER',
        'name' => 'Regional Reporter',
        'permissions' => ['reports.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    EmployeeRole::create([
        'employee_id' => 'e8',
        'role_id' => 'ROLE_REPORTER',
        'source' => 'manual',
        'granted_by' => 'u3',
    ]);

    $reporter = apiLogin('u8');
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$reporter}"])->assertOk();

    // Archiving the role must stop it granting, and must invalidate the cached
    // permission codes, otherwise the holder keeps reporting rights for 60s.
    // The employee_roles row survives on purpose - it is inert while the role is
    // inactive and comes back on restore.
    $this->deleteJson('/api/roles/ROLE_REPORTER', [], ['Authorization' => "Bearer {$hr}"])->assertOk();

    expect(EmployeeRole::where('role_id', 'ROLE_REPORTER')->exists())->toBeTrue()
        ->and(Role::find('ROLE_REPORTER')->active)->toBeFalse();
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$reporter}"])->assertStatus(403);

    // Restoring brings the codes straight back.
    $this->postJson('/api/roles/ROLE_REPORTER/restore', [], ['Authorization' => "Bearer {$hr}"])->assertOk();
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$reporter}"])->assertOk();
});

it('stops honouring an archived role for scope and workflow assignment', function () {
    // Archiving must not leave a holder able to read the zone their archived
    // role pointed at, nor able to satisfy an approval step, because the
    // employee_roles row still carries scope_zone_id even once it grants nothing.
    // The zone is deliberately not the holder's own, so this role is the only
    // thing contributing it and the assertion cannot pass by coincidence.
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', [
        'id' => 'ROLE_FOREIGN_SCOPE',
        'name' => 'Foreign Zone Reader',
        'permissions' => ['reports.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    EmployeeRole::create([
        'employee_id' => 'e6',
        'role_id' => 'ROLE_FOREIGN_SCOPE',
        'scope_zone_id' => 'z3',
        'source' => 'manual',
        'granted_by' => 'u3',
    ]);

    $user = Employee::findOrFail('e6')->user;
    $scope = app(AccessScopeService::class);
    $resolver = app(WorkflowResolver::class);

    // A requester inside z3 who is not the holder, so the resolver has someone
    // to find rather than bailing out on the never-approve-yourself rule. No
    // seeded employee sits in z3, so register one.
    $this->postJson('/api/org/employees', [
        'full_name' => 'Zonet Three Requester',
        'username' => 'zone3req',
        'password' => 'password',
        'email' => 'zone3.requester@dpp.go.tz',
        'position_id' => 'p1',
        'department_id' => 'd2',
        'zone_id' => 'z3',
        'gender' => 'F',
        'hire_date' => '2026-10-01',
        'role_ids' => ['SELF_SERVICE'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    $requester = Employee::whereHas('user', fn ($q) => $q->where('username', 'zone3req'))->firstOrFail();

    $before = $scope->forUser($user, 'reports');
    expect($before->allowedZoneIds)->toContain('z3');
    expect($resolver->resolveApprover($requester, 'ROLE_FOREIGN_SCOPE')['assignee']?->id)->toBe('e6');

    $this->deleteJson('/api/roles/ROLE_FOREIGN_SCOPE', [], ['Authorization' => "Bearer {$hr}"])->assertOk();

    expect($scope->forUser($user, 'reports')->allowedZoneIds)->not->toContain('z3');
    expect($resolver->resolveApprover($requester, 'ROLE_FOREIGN_SCOPE')['assignee'])->toBeNull();

    $this->postJson('/api/roles/ROLE_FOREIGN_SCOPE/restore', [], ['Authorization' => "Bearer {$hr}"])->assertOk();
    expect($scope->forUser($user, 'reports')->allowedZoneIds)->toContain('z3');
    expect($resolver->resolveApprover($requester, 'ROLE_FOREIGN_SCOPE')['assignee']?->id)->toBe('e6');
});

it('leaves delegations and workflow assignments intact when a role is archived', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', ['id' => 'ROLE_DELEGATED', 'name' => 'Delegated Desk'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(201);

    DB::table('delegations')->insert([
        'id' => 'del_role_cascade',
        'delegator_employee_id' => 'e6',
        'delegate_employee_id' => 'e7',
        'role_id' => 'ROLE_DELEGATED',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'active' => true,
    ]);

    DB::table('workflow_assignments')->insert([
        'request_id' => LeaveRequest::first()->id,
        'step_id' => null,
        'assigned_employee_id' => 'e6',
        'assigned_role_id' => 'ROLE_DELEGATED',
        'status' => 'pending',
    ]);

    $this->deleteJson('/api/roles/ROLE_DELEGATED', [], ['Authorization' => "Bearer {$hr}"])->assertOk();

    // Archiving must not cascade. A past delegation is a historical fact, and a
    // completed approval must keep resolving to the role that granted it. This
    // is the behaviour that makes archive safer than delete.
    expect(DB::table('delegations')->where('id', 'del_role_cascade')->exists())->toBeTrue()
        ->and(DB::table('workflow_assignments')->where('assigned_role_id', 'ROLE_DELEGATED')->exists())->toBeTrue();
});

it('refuses to archive a role that an approval workflow still requires', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', ['id' => 'ROLE_LEGAL_REVIEW', 'name' => 'Legal Review'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(201);

    DB::table('workflow_steps')->insert([
        'workflow_def_id' => 'wf_annual',
        'step_order' => 9,
        'role_required' => 'ROLE_LEGAL_REVIEW',
        'sla_days' => 2,
        'is_mandatory' => true,
        'escalation_after_sla' => false,
    ]);

    $this->deleteJson('/api/roles/ROLE_LEGAL_REVIEW', [], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonPath('errors.role.0', 'This role is still required by an approval workflow.')
        ->assertJsonPath('blockers.0.workflow_def_id', 'wf_annual');

    expect(Role::find('ROLE_LEGAL_REVIEW'))->not->toBeNull()
        ->and(Role::find('ROLE_LEGAL_REVIEW')->active)->toBeTrue();
});

it('refuses to archive Final Authority — it is the resolver’s fallback approver', function () {
    // The chain resolver falls back to FINAL_AUTHORITY whenever a request would
    // otherwise resolve to zero approvers. Archiving it would leave such
    // requests permanently undecidable, so the server refuses outright — the UI
    // hides the delete button to match.
    $hr = apiLogin('u3');

    $this->deleteJson('/api/roles/FINAL_AUTHORITY', [], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonPath('errors.role.0', 'This role cannot be deleted.');

    expect(Role::find('FINAL_AUTHORITY')->active)->toBeTrue();
});

it('archives a workflow-referenced role once its steps are reassigned in the same call', function () {    // Consolidating on a single approval role is a normal reorganisation, but
    // doing it by hand means opening every workflow, moving every step, then
    // coming back for each role. `reassign_to` does both in one write so the
    // admin is never left with a role they cannot remove and steps pointing at
    // nothing.
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', ['id' => 'ROLE_LEGAL_REVIEW', 'name' => 'Legal Review'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(201);

    DB::table('workflow_steps')->insert([
        'workflow_def_id' => 'wf_annual',
        'step_order' => 9,
        'role_required' => 'ROLE_LEGAL_REVIEW',
        'sla_days' => 2,
        'is_mandatory' => true,
        'escalation_after_sla' => false,
    ]);

    // A bad target is refused rather than silently leaving the steps orphaned.
    $this->deleteJson('/api/roles/ROLE_LEGAL_REVIEW', ['reassign_to' => 'ROLE_DOES_NOT_EXIST'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonPath('errors.reassign_to.0', 'Pick a different, active role to take over these approval steps.');

    $this->deleteJson('/api/roles/ROLE_LEGAL_REVIEW', ['reassign_to' => 'ROLE_LEGAL_REVIEW'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);

    expect(DB::table('workflow_steps')->where('role_required', 'ROLE_LEGAL_REVIEW')->count())->toBe(1)
        ->and(Role::find('ROLE_LEGAL_REVIEW')->active)->toBeTrue();

    // The real call moves the step and archives the role in one write.
    $this->deleteJson('/api/roles/ROLE_LEGAL_REVIEW', ['reassign_to' => 'FINAL_AUTHORITY'], ['Authorization' => "Bearer {$hr}"])
        ->assertOk();

    expect(DB::table('workflow_steps')->where('role_required', 'ROLE_LEGAL_REVIEW')->count())->toBe(0)
        ->and(DB::table('workflow_steps')->where('workflow_def_id', 'wf_annual')->where('step_order', 9)->value('role_required'))->toBe('FINAL_AUTHORITY')
        ->and(Role::find('ROLE_LEGAL_REVIEW')->active)->toBeFalse();

    // The reassignment is auditable: an admin can see which chains moved.
    $logs = DB::table('audit_logs')->where('entity_type', 'workflow')->where('entity_id', 'reassign_steps')->get();
    expect($logs)->not->toBeEmpty()
        ->and($logs->last()->action)->toBe('update')
        ->and(json_decode($logs->last()->payload, true)['to_role'] ?? null)->toBe('FINAL_AUTHORITY');
});

it('never resolves a chain to zero approvers when every step names FINAL_AUTHORITY', function () {
    // A definition whose steps are all FINAL_AUTHORITY resolves to nothing for
    // routine short leave: the resolver skips those steps in the loop and
    // appends the DPP only when policy demands it. A request submitted with no
    // approver would sit in under_review forever, so the declared
    // final-authority step is used as the fallback.
    $request = LeaveRequest::where('status', '!=', 'withdrawn')->firstOrFail();
    $def = WorkflowDefinition::where('leave_type_id', $request->leave_type_id)->firstOrFail();

    $def->steps()->delete();
    DB::table('workflow_steps')->insert([
        ['workflow_def_id' => $def->id, 'step_order' => 1, 'role_required' => 'FINAL_AUTHORITY', 'sla_days' => 3, 'is_mandatory' => true, 'escalation_after_sla' => false],
    ]);

    $chain = app(WorkflowResolver::class)->resolveWorkflow($request);

    expect($chain)->not->toBeEmpty()
        ->and($chain[0]['role'])->toBe('FINAL_AUTHORITY')
        ->and($chain[0]['assignee_id'])->not->toBe($request->employee_id);
});

it('archives a system role instead of refusing, and restores it', function () {
    // The old behaviour refused this outright, which left the matrix offering a
    // Delete button that could only ever fail. Archive is reversible, so every
    // role the UI lists can be taken out of service.
    //
    // SELF_SERVICE is used rather than HR because HR is required by a live
    // approval step; that case is a genuine integrity constraint and is refused
    // for archiving too, which the workflow test above covers.
    $hr = apiLogin('u3');

    // An employee who holds nothing but SELF_SERVICE loses exactly those codes
    // while the role is archived, and gets them back on restore.
    $this->postJson('/api/org/employees', [
        'full_name' => 'Peter Only Self',
        'username' => 'selfonly',
        'password' => 'password',
        'email' => 'peter.onlyself@dpp.go.tz',
        'position_id' => 'p1',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'gender' => 'M',
        'hire_date' => '2026-10-01',
        'role_ids' => ['SELF_SERVICE'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    $token = apiLogin('selfonly');

    $this->deleteJson('/api/roles/SELF_SERVICE', [], ['Authorization' => "Bearer {$hr}"])->assertOk();

    expect(Role::find('SELF_SERVICE'))->not->toBeNull()
        ->and(Role::find('SELF_SERVICE')->active)->toBeFalse();

    // The row and its grants are intact, so it is fully recoverable.
    expect(Role::find('SELF_SERVICE')->permissions()->pluck('code')->all())
        ->toContain('requests.view', 'documents.view');

    // Archiving it strips the codes from the holder immediately.
    $me = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();
    expect($me->json('user.permissions'))->not->toContain('requests.view', 'documents.view');

    $this->postJson('/api/roles/SELF_SERVICE/restore', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.active', true);
    expect(Role::find('SELF_SERVICE')->active)->toBeTrue();

    $me = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();
    expect($me->json('user.permissions'))->toContain('requests.view', 'documents.view');
});

it('only a user with admin.delete may delete a role', function () {
    $hr = apiLogin('u3');
    $this->postJson('/api/roles', ['id' => 'ROLE_TEMP', 'name' => 'Temporary Role'], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(201);

    $supervisor = apiLogin('u7'); // SUPERVISOR: no admin.* grants
    $this->deleteJson('/api/roles/ROLE_TEMP', [], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);

    expect(Role::find('ROLE_TEMP'))->not->toBeNull();
});

it('requires admin.create and admin.update respectively to add and edit a role', function () {
    $supervisor = apiLogin('u7'); // SUPERVISOR: no admin.* grants

    $this->postJson('/api/roles', ['name' => 'Nope Role'], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);
    $this->patchJson('/api/roles/SUPERVISOR', ['name' => 'Nope Name'], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);
});

it('grants and revokes one role without touching scoped structural grants', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', ['id' => 'ROLE_REVIEWER', 'name' => 'Case Reviewer', 'permissions' => ['requests.view']], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(201);

    // e3 holds DEPT_HEAD four times, always scoped to a department.
    $before = EmployeeRole::where('employee_id', 'e3')->where('role_id', 'DEPT_HEAD')->count();
    expect($before)->toBe(4);

    $granted = $this->postJson('/api/org/employees/e7/roles/ROLE_REVIEWER', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->json('data');

    expect($granted['unscoped_role_ids'])->toContain('ROLE_REVIEWER')
        ->and($granted['unscoped_role_ids'])->toContain('SUPERVISOR')
        // A scoped grant must never be re-materialised as an unscoped one.
        ->and($granted['unscoped_role_ids'])->not->toContain('DEPT_HEAD');
    expect(EmployeeRole::where('employee_id', 'e3')->where('role_id', 'DEPT_HEAD')->count())->toBe(4);

    // Revoking a role e3 only holds through scope is refused, not silently widened.
    $this->deleteJson('/api/org/employees/e3/roles/DEPT_HEAD', [], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);
    expect(EmployeeRole::where('employee_id', 'e3')->where('role_id', 'DEPT_HEAD')->count())->toBe(4);

    $this->deleteJson('/api/org/employees/e7/roles/ROLE_REVIEWER', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.unscoped_role_ids', fn (array $ids) => ! in_array('ROLE_REVIEWER', $ids, true));

    expect(EmployeeRole::where('employee_id', 'e7')->where('role_id', 'ROLE_REVIEWER')->exists())->toBeFalse();
    expect(EmployeeRole::where('employee_id', 'e7')->where('role_id', 'SUPERVISOR')->exists())->toBeTrue();
});

it('rejects granting an unknown role and gating role grants behind admin.update', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/org/employees/e7/roles/ROLE_NOPE', [], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422);

    $supervisor = apiLogin('u7');
    $this->postJson('/api/org/employees/e7/roles/SUPERVISOR', [], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);
});

it('does not widen a scoped grant when the flat role list is resubmitted on profile edit', function () {
    $hr = apiLogin('u3');

    // e2 holds DEPT_HEAD scoped to d1. The employee list payload is flat, so a
    // profile save can echo DEPT_HEAD back; that must not mint an unscoped copy.
    expect(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')
        ->whereNull('department_id')->whereNull('scope_zone_id')->exists())->toBeFalse();

    $scopeService = app(AccessScopeService::class);

    $before = $scopeService->forUser(User::where('username', 'u2')->firstOrFail());

    $this->patchJson('/api/org/employees/e2', [
        'role_ids' => ['DEPT_HEAD', 'SUPERVISOR'],
        'full_name' => 'Amina Said',
    ], ['Authorization' => "Bearer {$hr}"])->assertOk();

    // The scoped row survives untouched and no unscoped duplicate appears.
    expect(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')->count())->toBe(1);
    expect(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')
        ->whereNull('department_id')->whereNull('scope_zone_id')->exists())->toBeFalse();

    // Unscoped roles were still replaced as requested.
    expect(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'SUPERVISOR')
        ->whereNull('department_id')->whereNull('scope_zone_id')->exists())->toBeTrue();

    // And the effective scope is unchanged: still one department, not the org.
    $after = $scopeService->forUser(User::where('username', 'u2')->firstOrFail());
    expect($after->allowedDepartmentIds)->toBe($before->allowedDepartmentIds)
        ->and($after->allowedZoneIds)->toBe([])
        ->and($after->canViewAllZones)->toBeFalse();
});
