<?php

use App\Models\EmployeeRole;

/**
 * Two live-API regressions from the admin screen:
 *
 * 1. GET /org/positions serialised default_roles as pivot objects (the
 *    eager-loaded relation overrode the attribute under the same snake key),
 *    so the edit dialog PATCHed objects back and got
 *    "The default_role_ids.0 field must be a string." — every position
 *    default-role edit was impossible.
 *
 * 2. PATCH /org/employees/{id} ran canGrantRole() over EVERY id in role_ids,
 *    including roles the employee already holds, so the staff screen's flat
 *    list could never be saved by anyone who could not themselves grant
 *    FINAL_AUTHORITY — the DPP's own profile was unsavable.
 */
it('serialises position default_roles as a flat id array', function () {
    $admin = apiLogin('u1');

    $positions = $this->getJson('/api/org/positions', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    $p1 = collect($positions)->firstWhere('id', 'p1');

    expect($p1)->not->toBeNull()
        ->and($p1['default_roles'])->toBe(['FINAL_AUTHORITY']);
});

it('round-trips default_role_ids as strings on position update and create', function () {
    $admin = apiLogin('u1');

    $updated = $this->patchJson('/api/org/positions/p1', [
        'default_role_ids' => ['HR'],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data');

    expect($updated['default_roles'])->toBe(['HR']);

    // The list endpoint must agree with the update response: that is what the
    // edit dialog drafts from.
    $positions = $this->getJson('/api/org/positions', ['Authorization' => "Bearer {$admin}"])
        ->json('data');

    expect(collect($positions)->firstWhere('id', 'p1')['default_roles'])->toBe(['HR']);

    $created = $this->postJson('/api/org/positions', [
        'title' => 'Deputy DPP',
        'department_id' => 'd1',
        'zone_id' => 'z1',
        'tier' => 2,
        'default_role_ids' => ['SUPERVISOR'],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(201)
        ->json('data');

    expect($created['default_roles'])->toBe(['SUPERVISOR']);
});

it('lets a profile be re-saved with the roles the employee already holds', function () {
    $hr = apiLogin('u3');

    expect(EmployeeRole::where('employee_id', 'e1')->where('role_id', 'FINAL_AUTHORITY')
        ->whereNull('department_id')->whereNull('scope_zone_id')->exists())->toBeTrue();

    // e1 is the DPP and holds FINAL_AUTHORITY (position_default). The staff
    // screen echoes the flat role list back on every save; refusing it made
    // the whole profile unsavable for anyone who cannot grant final authority.
    $saved = $this->patchJson('/api/org/employees/e1', [
        'full_name' => 'Kept Authority',
        'role_ids' => ['FINAL_AUTHORITY'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->json('data');

    expect($saved['role_ids'])->toContain('FINAL_AUTHORITY')
        ->and(EmployeeRole::where('employee_id', 'e1')->where('role_id', 'FINAL_AUTHORITY')->exists())->toBeTrue();

    // Adding a NEW grantable role on the same call is still checked and applied.
    $withNew = $this->patchJson('/api/org/employees/e1', [
        'role_ids' => ['FINAL_AUTHORITY', 'SELF_SERVICE'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->json('data');

    expect($withNew['role_ids'])->toContain('SELF_SERVICE')
        ->and(EmployeeRole::where('employee_id', 'e1')->where('role_id', 'SELF_SERVICE')
            ->whereNull('department_id')->whereNull('scope_zone_id')->exists())->toBeTrue();
});

it('removes a scoped grant when the flat role list no longer carries it', function () {
    $hr = apiLogin('u3');

    // e2 holds DEPT_HEAD scoped to a department only (source head_of_department).
    // The edit dialog lists it in the same role picker, but PATCH used to skip
    // scoped rows entirely — unchecking it changed nothing and the revoke
    // endpoint refused it too, so the grant was unremovable.
    expect(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')
        ->whereNotNull('department_id')->exists())->toBeTrue();

    // Echoing the scoped role back keeps it, and never widens it to unscoped.
    $kept = $this->patchJson('/api/org/employees/e2', [
        'role_ids' => ['DEPT_HEAD', 'SUPERVISOR'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->json('data');

    expect($kept['role_ids'])->toContain('DEPT_HEAD')
        ->and(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')
            ->whereNull('department_id')->exists())->toBeFalse()
        ->and(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')
            ->whereNotNull('department_id')->exists())->toBeTrue();

    // Unchecking it in the picker (flat list without DEPT_HEAD) drops it.
    $saved = $this->patchJson('/api/org/employees/e2', [
        'role_ids' => ['SUPERVISOR'],
    ], ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->json('data');

    expect($saved['role_ids'])->toBe(['SUPERVISOR'])
        ->and(EmployeeRole::where('employee_id', 'e2')->where('role_id', 'DEPT_HEAD')->exists())->toBeFalse();
});

it('emits unique role_ids even when a role is held scoped several times', function () {
    $admin = apiLogin('u1');

    // e3 is head of four departments: the roles relation returns one row per
    // pivot, so role_ids used to repeat DEPT_HEAD and the frontend rendered
    // duplicate React keys ("two children with the same key, DEPT_HEAD").
    $e3 = collect($this->getJson('/api/org/employees', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data'))->firstWhere('id', 'e3');

    expect($e3)->not->toBeNull()
        ->and($e3['role_ids'])->toContain('DEPT_HEAD')
        ->and($e3['role_ids'])->toBe(array_values(array_unique($e3['role_ids'])));
});

it('still refuses minting final authority and refuses dropping it', function () {
    $hr = apiLogin('u3');

    // New FINAL_AUTHORITY (e8 does not hold it): the escalation guard still bites.
    $this->patchJson('/api/org/employees/e8', ['role_ids' => ['FINAL_AUTHORITY']], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_ids');

    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'FINAL_AUTHORITY')->exists())->toBeFalse();

    // Dropping it by omitting the id from the payload was reachable before.
    $this->patchJson('/api/org/employees/e1', ['role_ids' => []], ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role_ids');

    expect(EmployeeRole::where('employee_id', 'e1')->where('role_id', 'FINAL_AUTHORITY')
        ->whereNull('department_id')->whereNull('scope_zone_id')->exists())->toBeTrue();
});
