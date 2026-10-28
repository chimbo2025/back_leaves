<?php

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /org/employees/{id} — permanent staff removal next to activate /
 * deactivate. Guards (in order): own record, head appointment, own leave
 * history, appearance in approval/handover history. Everything else — login,
 * role grants, delegations, notifications — goes with the employee.
 */
it('deletes history-free staff and disables their login with them', function () {
    $admin = apiLogin('u1');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Scratch Staff',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => DB::table('departments')->value('id'),
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-01-05',
        'username' => 'scratch.staff',
        'password' => 'password',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    $this->deleteJson("/api/org/employees/{$staff['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('message', 'Staff member deleted.');

    expect(Employee::find($staff['id']))->toBeNull();

    // The login must not outlive the employee (the API answers 422).
    $this->postJson('/api/login', ['username' => 'scratch.staff', 'password' => 'password'])
        ->assertStatus(422);

    // Deletion is audited with a full snapshot of what was removed.
    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'employee',
        'entity_id' => $staff['id'],
        'action' => 'delete',
    ]);
});

it('refuses deleting your own staff record', function () {
    $admin = apiLogin('u1');

    $this->deleteJson('/api/org/employees/e1', [], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot delete your own staff record — ask another administrator.');

    expect(Employee::find('e1'))->not->toBeNull();
});

it('refuses a unit head until the appointment is cleared, then deletes', function () {
    $admin = apiLogin('u1');
    $department = (string) DB::table('departments')->value('id');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Soon-To-Be Head',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => $department,
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-02-02',
        'username' => 'soon.head',
        'password' => 'password',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    $this->putJson("/api/org/departments/{$department}/head", [
        'employee_id' => $staff['id'],
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();

    // Head doctrine: a unit never silently loses its head to a directory
    // delete — the appointment must be cleared explicitly first.
    $this->deleteJson("/api/org/employees/{$staff['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonPath('message', 'They head a department or zone — clear the head appointment first.');

    $this->deleteJson("/api/org/departments/{$department}/head", [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk();

    $this->deleteJson("/api/org/employees/{$staff['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk();
    expect(Employee::find($staff['id']))->toBeNull();
});

it('refuses deletion when the person has leave requests', function () {
    $admin = apiLogin('u1');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Has History',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => DB::table('departments')->value('id'),
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-03-03',
        'username' => 'has.history',
        'password' => 'password',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    // The create endpoint always drafts for the ACTOR, so place the history
    // row directly — the guard counts requests, whatever their origin.
    DB::table('leave_requests')->insert([
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

    $this->deleteJson("/api/org/employees/{$staff['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonPath('message', 'They have 1 leave request(s) — history is permanent. Deactivate the record instead.');

    expect(Employee::find($staff['id']))->not->toBeNull();
});

it('refuses deletion when the person appears in approval history', function () {
    $admin = apiLogin('u1');

    $staff = $this->postJson('/api/org/employees', [
        'full_name' => 'Was An Approver',
        'position_id' => DB::table('positions')->value('id'),
        'department_id' => DB::table('departments')->value('id'),
        'zone_id' => DB::table('zones')->value('id'),
        'hire_date' => '2026-04-04',
        'username' => 'was.approver',
        'password' => 'password',
    ], ['Authorization' => "Bearer {$admin}"])->assertCreated()->json('data');

    // Stand them on someone else's chain — approver rows are RESTRICT
    // foreign keys and the audit trail must keep naming real people.
    $assignment = DB::table('workflow_assignments')->first();
    DB::table('workflow_assignments')->where('id', $assignment->id)
        ->update(['assigned_employee_id' => $staff['id']]);

    $this->deleteJson("/api/org/employees/{$staff['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(422)
        ->assertJsonPath('message', 'They appear in approval or handover history — history is permanent. Deactivate the record instead.');

    expect(Employee::find($staff['id']))->not->toBeNull();
});

it('refuses employees without admin.delete', function () {
    $token = apiLogin('u9'); // e9 — SELF_SERVICE floor only

    $this->deleteJson('/api/org/employees/e3', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);

    expect(Employee::find('e3'))->not->toBeNull();
});
