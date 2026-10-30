<?php

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Force-delete staff. Linked leave history and structural appointments do not
 * block deletion; the operation is reasoned and audited. Self-delete remains
 * forbidden so an administrator cannot orphan their own account.
 */
it('force deletes an employee and audits the reason', function () {
    $admin = apiLogin('u1');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Scratch Staff',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => DB::table('departments')->value('id'),
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-01-05',
        'email' => 'scratch.staff@example.test',
        'username' => 'scratch.staff',
        'password' => 'Password123',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    $this->deleteJson("/api/org/employees/{$staff['id']}", [
        'reason' => 'Termination cleanup.',
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('message', 'Staff member permanently deleted.');

    expect(Employee::find($staff['id']))->toBeNull();
    $this->postJson('/api/login', ['username' => 'scratch.staff', 'password' => 'Password123'])
        ->assertStatus(422);

    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'employee',
        'entity_id' => $staff['id'],
        'action' => 'employee_force_deleted',
        'actor_user_id' => 'u1',
        'reason' => 'Termination cleanup.',
    ]);
});

it('requires a reason and refuses unauthorized or self force-deletes', function () {
    $admin = apiLogin('u1');
    $low = apiLogin('u7');

    $this->deleteJson('/api/org/employees/e2', [], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->deleteJson('/api/org/employees/e2', ['reason' => 'Unauthorized cleanup.'], ['Authorization' => "Bearer {$low}"])
        ->assertStatus(403);

    $this->deleteJson('/api/org/employees/e1', ['reason' => 'Self cleanup.'], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(403);

    expect(Employee::find('e1'))->not->toBeNull();
});

it('deletes a structural head without requiring a separate unappointment', function () {
    $admin = apiLogin('u1');
    $department = (string) DB::table('departments')->value('id');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Soon-To-Be Deleted Head',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => $department,
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-02-02',
        'email' => 'soon.deleted.head@example.test',
        'username' => 'soon.deleted.head',
        'password' => 'Password123',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    $this->putJson("/api/org/departments/{$department}/head", [
        'employee_id' => $staff['id'],
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();

    $this->deleteJson("/api/org/employees/{$staff['id']}", [
        'reason' => 'Remove appointed head.',
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();

    expect(Employee::find($staff['id']))->toBeNull();
    $this->assertDatabaseMissing('employee_roles', ['employee_id' => $staff['id']]);
});

it('deletes an employee with leave history and associated workflow references', function () {
    $admin = apiLogin('u1');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Has History',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => DB::table('departments')->value('id'),
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-03-03',
        'email' => 'has.history@example.test',
        'username' => 'has.history',
        'password' => 'Password123',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    $requestId = DB::table('leave_requests')->insertGetId([
        'ref' => 'LVE-SCRATCH-0001',
        'employee_id' => $staff['id'],
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(45)->toDateString(),
        'end_date' => now()->addDays(49)->toDateString(),
        'days' => 5,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson("/api/org/employees/{$staff['id']}", [
        'reason' => 'Remove employee and linked requests.',
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();

    expect(Employee::find($staff['id']))->toBeNull();
    $this->assertDatabaseMissing('leave_requests', ['id' => $requestId]);
});
