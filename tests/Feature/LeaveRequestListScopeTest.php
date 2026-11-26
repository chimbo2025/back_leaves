<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowAssignment;
use App\Policies\LeaveRequestPolicy;
use App\Services\AccessScopeService;
use Illuminate\Support\Facades\DB;

/*
 * Who may see which leave request in the Leave Requests list.
 *
 * Intended rule (see AccessScopeService::applyRequestListScope): a request is
 * visible only if it is the caller's own, the caller is on ITS approval chain,
 * the requester sits inside an explicit zone/department/division/unit grant the
 * caller holds, or the caller holds requests.view_cross_zone (HR, FINAL_AUTHORITY
 * and BREAK_GLASS). Nothing else grants visibility.
 *
 * Every fixture is created here with its own ids; nothing depends on which
 * seeded employee happens to sit where. The suite runs on in-memory SQLite
 * inside RefreshDatabase (see phpunit.xml), never on a real database.
 */

/** Create user + employee with the given placement. */
function scopeActor(string $key, array $placement = []): Employee
{
    $user = User::create([
        'id' => "su_{$key}",
        'username' => "su_{$key}",
        'password' => 'password',
        'is_active' => true,
    ]);

    return Employee::create(array_merge([
        'id' => "se_{$key}",
        'user_id' => "su_{$key}", // literal id: User has no $incrementing=false, so $user->id would read back as the row number
        'full_name' => "Scope {$key}",
        'hire_date' => '2020-01-01',
        'is_active' => true,
    ], $placement));
}

function scopeGrant(Employee $employee, string $roleId, array $scope = []): void
{
    EmployeeRole::create(array_merge([
        'employee_id' => $employee->id,
        'role_id' => $roleId,
        'source' => 'test',
        'active' => true,
    ], $scope));
}

function scopeRequest(Employee $requester, string $ref): LeaveRequest
{
    return LeaveRequest::create([
        'ref' => $ref,
        'employee_id' => $requester->id,
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'days' => 5,
        'status' => 'submitted',
        'reason' => 'scope test',
    ]);
}

/** @return array<int,int> sorted ids of the test's requests that $employee can list */
function visibleTo(Employee $employee, array $requestIds): array
{
    $user = User::find($employee->user_id);
    $ids = app(AccessScopeService::class)
        ->applyLeaveRequestScope(LeaveRequest::query()->whereIn('id', $requestIds), $user)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    return $ids;
}

beforeEach(function () {
    // Organisation: zone z1 holds department A (with division DIV and unit UNI
    // beneath it) and department B; zone z2 holds department C.
    $this->deptA = Department::create(['id' => 'tA', 'name' => 'Dept A', 'zone_id' => 'z1', 'unit_type' => 'department', 'is_active' => true]);
    $this->deptB = Department::create(['id' => 'tB', 'name' => 'Dept B', 'zone_id' => 'z1', 'unit_type' => 'department', 'is_active' => true]);
    $this->deptC = Department::create(['id' => 'tC', 'name' => 'Dept C', 'zone_id' => 'z2', 'unit_type' => 'department', 'is_active' => true]);
    $this->division = Department::create(['id' => 'tDIV', 'name' => 'Division', 'zone_id' => 'z1', 'parent_department_id' => 'tA', 'unit_type' => 'division', 'is_active' => true]);
    $this->unit = Department::create(['id' => 'tUNI', 'name' => 'Unit', 'zone_id' => 'z1', 'parent_department_id' => 'tA', 'unit_type' => 'unit', 'is_active' => true]);

    // Requesters.
    $this->rA = scopeActor('rA', ['department_id' => 'tA', 'zone_id' => 'z1']);
    $this->rB = scopeActor('rB', ['department_id' => 'tB', 'zone_id' => 'z1']);
    $this->rC = scopeActor('rC', ['department_id' => 'tC', 'zone_id' => 'z2']);
    $this->rDiv = scopeActor('rDiv', ['department_id' => 'tA', 'division_id' => 'tDIV', 'zone_id' => 'z1']);
    $this->rUni = scopeActor('rUni', ['department_id' => 'tA', 'unit_id' => 'tUNI', 'zone_id' => 'z1']);

    $this->reqA = scopeRequest($this->rA, 'SC-A');
    $this->reqB = scopeRequest($this->rB, 'SC-B');
    $this->reqC = scopeRequest($this->rC, 'SC-C');
    $this->reqDiv = scopeRequest($this->rDiv, 'SC-DIV');
    $this->reqUni = scopeRequest($this->rUni, 'SC-UNI');

    $this->all = [$this->reqA->id, $this->reqB->id, $this->reqC->id, $this->reqDiv->id, $this->reqUni->id];
});

it('lets an ordinary member see only their own request', function () {
    expect(visibleTo($this->rA, $this->all))->toBe([$this->reqA->id]);
});

it('lets a department head see their department but not another department in the same zone', function () {
    $head = scopeActor('headA', ['department_id' => 'tA', 'zone_id' => 'z1']);
    scopeGrant($head, 'DEPT_HEAD', ['department_id' => 'tA']);

    $visible = visibleTo($head, $this->all);

    expect($visible)->toContain($this->reqA->id)
        ->and($visible)->not->toContain($this->reqB->id)
        ->and($visible)->not->toContain($this->reqC->id);
});

it('lets a division head see requesters of that division only', function () {
    $head = scopeActor('headDiv', ['department_id' => 'tA', 'zone_id' => 'z1']);
    scopeGrant($head, 'DEPT_HEAD', ['department_id' => 'tDIV']);

    expect(visibleTo($head, $this->all))->toBe([$this->reqDiv->id]);
});

it('lets a unit head see requesters of that unit only', function () {
    $head = scopeActor('headUni', ['department_id' => 'tA', 'zone_id' => 'z1']);
    scopeGrant($head, 'DEPT_HEAD', ['department_id' => 'tUNI']);

    expect(visibleTo($head, $this->all))->toBe([$this->reqUni->id]);
});

it('lets a zonal head see their zone and no other zone', function () {
    $head = scopeActor('headZ', ['department_id' => 'tC', 'zone_id' => 'z2']);
    scopeGrant($head, 'ZONAL_HEAD', ['scope_zone_id' => 'z1']);

    $visible = visibleTo($head, $this->all);

    expect($visible)->toContain($this->reqA->id, $this->reqB->id, $this->reqDiv->id, $this->reqUni->id)
        ->and($visible)->not->toContain($this->reqC->id);
});

it('lets a central approver with no placement see exactly the requests assigned to them', function () {
    $approver = scopeActor('central'); // no department, division, unit or zone
    WorkflowAssignment::create([
        'request_id' => $this->reqB->id,
        'assigned_employee_id' => $approver->id,
        'assigned_role_id' => 'HR',
        'status' => 'pending',
    ]);

    expect(visibleTo($approver, $this->all))->toBe([$this->reqB->id]);
});

it('does not let an approval role in one department reveal another department', function () {
    // Placed in department B and assigned only on B's request: A and C stay hidden.
    $approver = scopeActor('approverB', ['department_id' => 'tB', 'zone_id' => 'z1']);
    WorkflowAssignment::create([
        'request_id' => $this->reqB->id,
        'assigned_employee_id' => $approver->id,
        'assigned_role_id' => 'SUPERVISOR',
        'status' => 'pending',
    ]);

    $visible = visibleTo($approver, $this->all);

    expect($visible)->toBe([$this->reqB->id])
        ->and($visible)->not->toContain($this->reqA->id);
});

it('gives organisation-wide reach to HR through view_cross_zone', function () {
    $hr = scopeActor('hr', ['department_id' => 'tB', 'zone_id' => 'z1']);
    scopeGrant($hr, 'HR');

    expect(visibleTo($hr, $this->all))->toEqualCanonicalizing($this->all);
});

it('does not give organisation-wide reach to a role that lacks view_cross_zone', function () {
    Role::create(['id' => 'TEST_DIRECTOR', 'name' => 'Test Director', 'approval_tier' => 3, 'is_final_authority' => false, 'is_system' => false, 'active' => true]);
    $director = scopeActor('director', ['department_id' => 'tB', 'zone_id' => 'z1']);
    scopeGrant($director, 'TEST_DIRECTOR');

    expect(visibleTo($director, $this->all))->toBe([]);
});

it('shows organisation-wide reach to a non-central role only when someone granted it the code', function () {
    $permission = Permission::where('code', 'requests.view_cross_zone')->firstOrFail();
    Role::create(['id' => 'TEST_DIRECTOR', 'name' => 'Test Director', 'approval_tier' => 3, 'is_final_authority' => false, 'is_system' => false, 'active' => true]);
    DB::table('role_permissions')->insert(['role_id' => 'TEST_DIRECTOR', 'permission_id' => $permission->id]);
    $director = scopeActor('director', ['department_id' => 'tB', 'zone_id' => 'z1']);
    scopeGrant($director, 'TEST_DIRECTOR');

    expect(visibleTo($director, $this->all))->toEqualCanonicalizing($this->all);
});

it('keeps the detail endpoint policy consistent with the list', function () {
    $policy = app(LeaveRequestPolicy::class);

    $divHead = scopeActor('headDiv', ['department_id' => 'tA', 'zone_id' => 'z1']);
    scopeGrant($divHead, 'DEPT_HEAD', ['department_id' => 'tDIV']);
    $divUser = User::find($divHead->user_id);

    $unitHead = scopeActor('headUni', ['department_id' => 'tA', 'zone_id' => 'z1']);
    scopeGrant($unitHead, 'DEPT_HEAD', ['department_id' => 'tUNI']);
    $unitUser = User::find($unitHead->user_id);

    expect($policy->view($divUser, $this->reqDiv))->toBeTrue()
        ->and($policy->view($divUser, $this->reqUni))->toBeFalse()
        ->and($policy->view($divUser, $this->reqB))->toBeFalse()
        ->and($policy->view($unitUser, $this->reqUni))->toBeTrue()
        ->and($policy->view($unitUser, $this->reqDiv))->toBeFalse();
});
