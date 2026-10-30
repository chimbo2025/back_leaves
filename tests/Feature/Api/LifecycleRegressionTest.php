<?php

use App\Http\Middleware\CheckPermission;
use App\Models\CaseHandover;
use App\Models\Delegation;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaseHandoverService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lifecycle regressions.
 *
 * Each test here covers a defect that was live and untested:
 *
 *   1. POST /org/employees wrote effective_to => null for every role, skipping
 *      the break-glass clamp that update() and grantRole() both apply.
 *   2. grantRole() accepted an archived role (exists() has no active check).
 *   3. DelegationController::store read the raw roles() relation, ignoring
 *      employee_roles.active, the effective window and roles.active.
 *   4. POST /roles/{id}/restore demanded admin.create while the archive it undoes
 *      took admin.delete, so edit-only admins could not undo an archive.
 *   5. CheckPermission short-circuited on a `*` wildcard.
 */
it('never leaves a break-glass grant unbounded when an employee is created', function () {
    // FINAL_AUTHORITY (u1) holds every code including leave_payments.*,
    // so it can grant BREAK_GLASS. HR (u3) does not hold leave_payments.*.
    $dpp = apiLogin('u1');

    $employee = $this->postJson('/api/org/employees', [
        'full_name' => 'Temporary Elevated Officer',
        'position_id' => 'p1',
        'department_id' => 'd1',
        'zone_id' => 'z1',
        'hire_date' => '2026-01-05',
        'username' => 'temp_elevated',
        'password' => 'password',
        'role_ids' => ['BREAK_GLASS'],
    ], ['Authorization' => "Bearer {$dpp}"])
        ->assertStatus(201)
        ->json('data');

    $grant = EmployeeRole::where('employee_id', $employee['id'])
        ->where('role_id', 'BREAK_GLASS')
        ->firstOrFail();

    // The defect: this path skipped breakGlassExpiry() entirely, so the safety
    // valve that exists to be temporary could be minted as a standing privilege.
    expect($grant->effective_to)->not->toBeNull('A break-glass grant created with the employee must carry the expiry clamp.');

    $expiresAt = Carbon::parse($grant->effective_to);
    expect($expiresAt->isFuture())->toBeTrue("Break-glass expiry {$grant->effective_to} is not in the future.");
    expect((int) now()->diffInDays($expiresAt, true))
        ->toBeLessThanOrEqual(PermissionService::BREAK_GLASS_MAX_DAYS);
});

it('refuses to grant an archived role', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', [
        'name' => 'Sunset Records',
        'permissions' => ['requests.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    $this->deleteJson('/api/roles/ROLE_SUNSET_RECORDS', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    // exists() still matches, so the pre-fix guard passed and the role re-appeared
    // in the matrix as though it were live configuration.
    expect(Role::where('id', 'ROLE_SUNSET_RECORDS')->exists())->toBeTrue();

    $this->postJson('/api/org/employees/e8/roles/ROLE_SUNSET_RECORDS', [], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'ROLE_SUNSET_RECORDS')->exists())
        ->toBeFalse();
});

it('refuses to delegate a role that is archived, withdrawn or lapsed', function () {
    // u7 is the seeded SUPERVISOR, and a user may only delegate a role they
    // personally hold, so this has to run as u7 rather than as HR.
    $supervisorToken = apiLogin('u7');
    $supervisor = Employee::whereHas('user', fn ($q) => $q->where('username', 'u7'))->firstOrFail();

    // delegations.create is a permission in its own right; u7 holds it, HR does not.
    $this->postJson('/api/delegations', [
        'role_id' => 'SUPERVISOR',
        'delegate_employee_id' => 'e8',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
        'reason' => 'Cover while away.',
    ], ['Authorization' => "Bearer {$supervisorToken}"])->assertCreated();

    // Withdraw the grant. The raw roles() relation still returns the row, so
    // before the fix the delegator could hand on an authority they had lost.
    $grant = EmployeeRole::where('employee_id', $supervisor->id)
        ->where('role_id', 'SUPERVISOR')
        ->whereNull('department_id')
        ->whereNull('scope_zone_id')
        ->firstOrFail();
    $grant->update(['active' => false]);

    $delegate = $supervisor->id === 'e7' ? 'e8' : 'e7';

    $this->postJson('/api/delegations', [
        'role_id' => 'SUPERVISOR',
        'delegate_employee_id' => $delegate,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
        'reason' => 'Cover after withdrawal.',
    ], ['Authorization' => "Bearer {$supervisorToken}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    // And an expired grant, for the same reason.
    $grant->update(['active' => true, 'effective_to' => now()->subDay()->toDateString()]);

    $this->postJson('/api/delegations', [
        'role_id' => 'SUPERVISOR',
        'delegate_employee_id' => $delegate,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
        'reason' => 'Cover after lapse.',
    ], ['Authorization' => "Bearer {$supervisorToken}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    expect(Delegation::where('role_id', 'SUPERVISOR')->count())->toBe(1, 'Only the delegation made while the role was genuinely held should exist.');
});

it('lets an admin.update holder restore an archived role', function () {
    $hr = apiLogin('u3');

    $this->postJson('/api/roles', [
        'name' => 'Winter Cover',
        'permissions' => ['requests.view', 'requests.create'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    $this->deleteJson('/api/roles/ROLE_WINTER_COVER', [], ['Authorization' => "Bearer {$hr}"])->assertOk();
    expect(Role::find('ROLE_WINTER_COVER')->active)->toBeFalse();

    // Archive takes admin.delete, restore must not demand admin.create: that
    // combination let an org archive roles it could not bring back.
    $this->postJson('/api/roles/ROLE_WINTER_COVER/restore', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('data.active', true);

    expect(Role::find('ROLE_WINTER_COVER')->active)->toBeTrue();

    // Permissions came back exactly as they were, not as a fresh default set.
    expect(Role::find('ROLE_WINTER_COVER')->permissions()->pluck('code')->sort()->values()->all())
        ->toBe(['requests.create', 'requests.view']);
});

it('refuses a wildcard permission route instead of bypassing the check', function () {
    $hr = apiLogin('u3');

    // A `permission:*` typo would have short-circuited CheckPermission and opened
    // that route to every authenticated user. There is no such route today, so
    // this exercises the middleware directly.
    $request = Request::create('/api/anything', 'GET');
    $request->setUserResolver(fn () => $request->user());

    $middleware = new CheckPermission(app(PermissionService::class));

    $user = User::where('username', 'u1')->firstOrFail();
    $request->setUserResolver(fn () => $user);

    $response = $middleware->handle($request, fn () => response('passed'), '*');

    expect($response->getStatusCode())->toBe(500, 'A wildcard permission must fail loudly, not grant access.');
    expect($response->getContent())->toContain('Wildcard permissions are not supported');
});

it('refuses to mint authority the caller does not hold', function () {
    // u3 is HR: 48 codes, admin.*, but NOT final authority.
    $hr = apiLogin('u3');

    // 1. The escalation. admin.update alone used to accept any role id, so one
    // request could create a FINAL_AUTHORITY holder - 48 permissions including
    // all six view_cross_zone codes, i.e. org-wide reach over every module.
    $this->postJson('/api/org/employees/e8/roles/FINAL_AUTHORITY', [], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'FINAL_AUTHORITY')->exists())->toBeFalse();

    // Also refused through the bulk paths, which is where it was easiest to slip
    // in: role_ids on create and on patch.
    $this->postJson('/api/org/employees', [
        'full_name' => 'Shadow Authority',
        'position_id' => 'p1',
        'department_id' => 'd1',
        'zone_id' => 'z1',
        'hire_date' => '2026-01-05',
        'username' => 'shadow_authority',
        'password' => 'password',
        'role_ids' => ['FINAL_AUTHORITY'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_ids');

    // Nothing was half-created: the escalation check runs before the user and
    // employee rows are written.
    expect(User::where('username', 'shadow_authority')->exists())->toBeFalse();
    expect(Employee::where('full_name', 'Shadow Authority')->exists())->toBeFalse();

    $this->patchJson('/api/org/employees/e8', ['role_ids' => ['FINAL_AUTHORITY']], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_ids');

    // 2. Upward escalation into a narrower admin. HR carries all 48 codes, so it
    // can never exceed itself; the actor for this half is a purpose-built
    // limited admin that can call the endpoint but holds less than the target.
    $this->postJson('/api/roles', [
        'name' => 'Limited Admin',
        'permissions' => ['requests.view', 'reports.view', 'admin.view', 'admin.create', 'admin.update'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    $this->postJson('/api/roles', [
        'name' => 'Destructive Auditor',
        'permissions' => ['requests.view', 'admin.view', 'admin.delete'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    // HR holds every code, so HR may hand the limited admin role out.
    $this->postJson('/api/org/employees/e10/roles/ROLE_LIMITED_ADMIN', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk();

    $limited = apiLogin('u10');

    // Holding admin.update reaches the grant endpoint...
    $this->postJson('/api/org/employees/e8/roles/ROLE_LIMITED_ADMIN', [], ['Authorization' => "Bearer {$limited}"])
        ->assertOk();

    // ...but not to hand out a role carrying admin.delete, which it does not hold.
    $this->postJson('/api/org/employees/e8/roles/ROLE_DESTRUCTIVE_AUDITOR', [], ['Authorization' => "Bearer {$limited}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'ROLE_DESTRUCTIVE_AUDITOR')->exists())->toBeFalse();

    // A role carrying only codes the limited admin does hold is fine.
    $this->postJson('/api/roles', [
        'name' => 'Read Only Clerk',
        'permissions' => ['requests.view', 'reports.view'],
    ], ['Authorization' => "Bearer {$limited}"])->assertStatus(201);

    $this->postJson('/api/org/employees/e8/roles/ROLE_READ_ONLY_CLERK', [], ['Authorization' => "Bearer {$limited}"])
        ->assertOk();
});

it('refuses a handover proposal from someone who can read but not act', function () {
    // The propose endpoint used to authorise with the `view` policy, a READ
    // predicate. That let anyone who could see a request move a live court case
    // to any colleague they named.
    $hr = apiLogin('u3');
    $hrUser = User::where('username', 'u3')->firstOrFail();

    // Find a request owned by somebody other than the caller, that the caller can
    // see, and whose owner still has an open court case.
    $target = null;

    foreach (LeaveRequest::with('employee')->get() as $candidate) {
        if (app(CaseHandoverService::class)->openCourtCases($candidate->employee)->isNotEmpty()) {
            $target = $candidate;

            break;
        }
    }

    expect($target)->not->toBeNull('Fixture must contain a request whose owner has an open court case.');

    // HR can see it and can propose (central HR administering org-wide).
    $hrPropose = $this->postJson("/api/handovers/{$target->id}/propose", ['to_employee_id' => 'e7'], ['Authorization' => "Bearer {$hr}"]);
    $hrPropose->assertStatus(201);

    // Now a caller who can legitimately READ the request but holds none of the
    // three proposal authorities. The seeded roles all fail this: SELF_SERVICE
    // cannot see another officer's request, while every role that can see
    // across offices carries requests.approve. So build the exact case - a
    // zone-scoped reader with no approval authority at all.
    $this->postJson('/api/roles', [
        'name' => 'Zone Reader',
        'permissions' => ['requests.view', 'reports.view'],
    ], ['Authorization' => "Bearer {$hr}"])->assertStatus(201);

    $reader = User::where('username', 'u10')->firstOrFail();
    $readerEmployee = $reader->employee;
    $targetZone = $target->employee->zone_id;

    EmployeeRole::create([
        'employee_id' => $readerEmployee->id,
        'role_id' => 'ROLE_ZONE_READER',
        'source' => 'manual_grant',
        'granted_by' => $hrUser->id,
        'effective_from' => now()->toDateString(),
        'effective_to' => null,
        'scope_zone_id' => $targetZone,
    ]);
    app(PermissionService::class)->forget($reader);

    $readerToken = apiLogin('u10');

    $this->assertTrue(
        Gate::forUser($reader)->allows('view', $target),
        'Fixture assumption: the zone reader can legitimately read the request.'
    );

    // Read access alone is not write access.
    $this->postJson("/api/handovers/{$target->id}/propose", ['to_employee_id' => 'e8'], ['Authorization' => "Bearer {$readerToken}"])
        ->assertStatus(403);

    expect(CaseHandover::where('request_id', $target->id)->where('to_employee_id', 'e8')->exists())->toBeFalse();

    // And the requester, who holds none of those codes either, may still propose
    // their own handovers - the fix must not lock the officer out of handing
    // over the very cases the rule is about.
    $ownerToken = apiLogin(User::where('id', $target->employee->user_id)->value('username'));

    $this->postJson("/api/handovers/{$target->id}/propose", ['to_employee_id' => 'e8'], ['Authorization' => "Bearer {$ownerToken}"])
        ->assertStatus(201);
});

it('tells the matrix which codes are actually grantable', function () {
    $hr = apiLogin('u3');

    $payload = $this->getJson('/api/roles/permissions', ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->json();

    // Sourced from the modules table, so the matrix groups by the real module
    // list instead of the free-text permissions.module column.
    expect($payload)->toHaveKeys(['data', 'modules']);
    expect($payload['modules'])->toHaveCount(19);
    expect(array_keys($payload['modules']))->toContain('requests', 'policies', 'documents', 'admin', 'delegations', 'reports', 'staff', 'leave_plans', 'leave_waivers', 'leave_returns', 'leave_payments', 'leave_documents', 'report_exports', 'audit_logs', 'notifications', 'employees', 'leave_requests');

    foreach ($payload['data'] as $code) {
        expect($code)->toHaveKeys(['code', 'module', 'action', 'status', 'active', 'enforcement_reference']);
        // The UI must not have to re-derive grantability in TypeScript, where it
        // could drift from the server's own rule.
        expect($code['is_grantable'])->toBe($code['active'] && $code['status'] === 'implemented');
    }

    // Turning a code planned must flip the matrix switch off, not just hide it.
    Permission::where('code', 'requests.view')->update(['status' => 'planned', 'active' => false]);

    $planned = collect($this->getJson('/api/roles/permissions', ['Authorization' => "Bearer {$hr}"])->json('data'))
        ->firstWhere('code', 'requests.view');

    expect($planned['status'])->toBe('planned');
    expect($planned['is_grantable'])->toBeFalse();
});
