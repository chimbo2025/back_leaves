<?php

use App\Models\EmployeeRole;

/**
 * Scoped head grants (head of d1, zonal head of z4) stored their scope but
 * nothing surfaced it: the staff table said "Department Head" without naming a
 * department, and no endpoint could appoint a head to a particular unit at
 * all — the grants were seed-only and unremovable until the profile replace
 * path learned to drop them.
 */
it('exposes which department/zone each head grant covers', function () {
    $admin = apiLogin('u1');

    $departments = $this->getJson('/api/org/departments', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    $d1 = collect($departments)->firstWhere('id', 'd1');
    expect($d1['head'] ?? null)->not->toBeNull()
        ->and($d1['head']['employee_id'])->toBe('e3');

    $zones = $this->getJson('/api/org/zones', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    expect(collect($zones)->firstWhere('id', 'z2')['head']['employee_id'])->toBe('e4');

    // The staff payload carries the scope so the UI can name it per employee.
    $e3 = collect($this->getJson('/api/org/employees', ['Authorization' => "Bearer {$admin}"])
        ->json('data'))->firstWhere('id', 'e3');

    $deptHeadScopes = collect($e3['scoped_grants'])
        ->where('role_id', 'DEPT_HEAD')
        ->pluck('department_id')
        ->sort()
        ->values()
        ->all();

    expect($deptHeadScopes)->toBe(['d1', 'd6', 'd7', 'd8'])
        ->and($e3['role_ids'])->toContain('DEPT_HEAD', 'HR');
});

it('appoints, replaces and clears a department head for one department', function () {
    $admin = apiLogin('u1');

    // Appoint: replaces whoever held it, scoped to exactly this department.
    $appointed = $this->putJson('/api/org/departments/d1/head', [
        'employee_id' => 'e8',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    expect($appointed['head']['employee_id'])->toBe('e8')
        ->and(EmployeeRole::where('role_id', 'DEPT_HEAD')->where('department_id', 'd1')->count())->toBe(1)
        ->and(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'DEPT_HEAD')
            ->where('department_id', 'd1')->where('source', 'head_of_department')->exists())->toBeTrue();

    // e3 keeps the other departments: only d1 moved.
    expect(EmployeeRole::where('employee_id', 'e3')->where('role_id', 'DEPT_HEAD')
        ->pluck('department_id')->sort()->values()->all())->toBe(['d6', 'd7', 'd8']);

    // Replace again: the outgoing head loses the grant, still one row for d1.
    $replaced = $this->putJson('/api/org/departments/d1/head', [
        'employee_id' => 'e3',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    expect($replaced['head']['employee_id'])->toBe('e3')
        ->and(EmployeeRole::where('role_id', 'DEPT_HEAD')->where('department_id', 'd1')->count())->toBe(1)
        ->and(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'DEPT_HEAD')->exists())->toBeFalse();

    // Clear: the unit ends up with no head at all (idempotent).
    $cleared = $this->deleteJson('/api/org/departments/d1/head', [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    expect($cleared['head'])->toBeNull()
        ->and(EmployeeRole::where('role_id', 'DEPT_HEAD')->where('department_id', 'd1')->exists())->toBeFalse();

    $this->deleteJson('/api/org/departments/d1/head', [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk();
});

it('appoints and clears a zonal head', function () {
    $admin = apiLogin('u1');

    $appointed = $this->putJson('/api/org/zones/z1/head', [
        'employee_id' => 'e8',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    expect($appointed['head']['employee_id'])->toBe('e8')
        ->and(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'ZONAL_HEAD')
            ->where('scope_zone_id', 'z1')->where('source', 'zonal_head')->exists())->toBeTrue();

    // The seeded zonal head of z2 is untouched: scopes are per zone.
    expect(EmployeeRole::where('employee_id', 'e4')->where('role_id', 'ZONAL_HEAD')
        ->where('scope_zone_id', 'z2')->exists())->toBeTrue();

    $this->deleteJson('/api/org/zones/z1/head', [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.head', null);

    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'ZONAL_HEAD')->exists())->toBeFalse();
});

it('gates head appointment behind admin.update', function () {
    $supervisor = apiLogin('u7');

    $this->putJson('/api/org/departments/d1/head', ['employee_id' => 'e7'], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);

    $this->deleteJson('/api/org/departments/d1/head', [], ['Authorization' => "Bearer {$supervisor}"])
        ->assertStatus(403);

    expect(EmployeeRole::where('role_id', 'DEPT_HEAD')->where('department_id', 'd1')
        ->where('employee_id', 'e7')->exists())->toBeFalse();
});
