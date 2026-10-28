<?php

use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;

/**
 * Handover access comes only from case_handovers.* — never from admin.*.
 *
 * Every handover/case route carries its module code on top of the
 * recipient/scope policy checks, so the whole surface is visible in the
 * matrix and an admin code can never unlock it by accident.
 */
function grantHandoverProbe(string $code, string $employeeId = 'e11'): void
{
    [$module, $action] = explode('.', $code, 2);

    $role = Role::firstOrCreate(
        ['id' => 'PROBE_'.strtoupper(str_replace('.', '_', $code))],
        ['name' => "Probe {$code}", 'is_system' => false]
    );
    $role->permissions()->syncWithoutDetaching([
        Permission::where('module', $module)->where('action', $action)->value('id'),
    ]);
    EmployeeRole::create([
        'employee_id' => $employeeId,
        'role_id' => $role->id,
        'source' => 'manual_grant',
        'effective_from' => now()->toDateString(),
    ]);

    Cache::flush();
}

function stripToAdminViewOnly(): string
{
    // e11 keeps exactly one code (admin.view): close enough to "an admin"
    // to prove admin access never implies handover access.
    $hr = apiLogin('u3');
    test()->deleteJson('/api/org/employees/e11/roles/SELF_SERVICE', [], ['Authorization' => "Bearer {$hr}"])->assertOk();
    grantHandoverProbe('admin.view', 'e11');

    return apiLogin('u11');
}

function handoverFixture(): array
{
    // e9 owns open court case CR-2026-0142: propose as HR, accept as e8.
    $requester = apiLogin('u9');
    // e9 carries seeded maternity leave 2026-10-01..2026-12-23, so the
    // fixture lives in January 2027 to avoid the overlap guard.
    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2027-01-12',
        'end_date' => '2027-01-16',
        'reason' => 'Annual leave — handover isolation fixture.',
    ], ['Authorization' => "Bearer {$requester}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');
    test()->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requester}"])->assertOk();

    $proposed = test()->postJson("/api/handovers/{$requestId}/propose", [
        'to_employee_id' => 'e8',
    ], ['Authorization' => 'Bearer '.apiLogin('u3')]);
    $proposed->assertStatus(201);

    return [$requestId, $proposed->json('data.0.id')];
}

it('refuses every handover surface to an admin-only caller', function () {
    [$requestId, $handoverId] = handoverFixture();
    $adminProbe = stripToAdminViewOnly();

    $headers = ['Authorization' => "Bearer {$adminProbe}"];
    expect($this->getJson('/api/cases', $headers)->status())->toBe(403);
    expect($this->getJson('/api/cases/can-final-approve', $headers)->status())->toBe(403);
    expect($this->getJson("/api/handovers/{$requestId}", $headers)->status())->toBe(403);
    expect($this->postJson("/api/handovers/{$requestId}/propose", ['to_employee_id' => 'e8'], $headers)->status())->toBe(403);
    expect($this->postJson("/api/handovers/{$handoverId}/accept", [], $headers)->status())->toBe(403);
    expect($this->postJson("/api/handovers/{$handoverId}/reject", [], $headers)->status())->toBe(403);
    expect($this->postJson("/api/handovers/{$handoverId}/complete", [], $headers)->status())->toBe(403);
});

it('refuses every handover surface without any handover code', function () {
    [$requestId, $handoverId] = handoverFixture();

    $hr = apiLogin('u3');
    $this->deleteJson('/api/org/employees/e11/roles/SELF_SERVICE', [], ['Authorization' => "Bearer {$hr}"])->assertOk();
    $headers = ['Authorization' => 'Bearer '.apiLogin('u11')];

    expect($this->getJson('/api/cases', $headers)->status())->toBe(403);
    expect($this->postJson("/api/handovers/{$requestId}/propose", ['to_employee_id' => 'e8'], $headers)->status())->toBe(403);
    expect($this->postJson("/api/handovers/{$handoverId}/accept", [], $headers)->status())->toBe(403);
});

it('still lets the recipient accept and complete with the module codes', function () {
    [, $handoverId] = handoverFixture();

    // e8 is the named recipient and holds the codes via SELF_SERVICE.
    $this->postJson("/api/handovers/{$handoverId}/accept", [], ['Authorization' => 'Bearer '.apiLogin('u8')])->assertOk();
    $this->assertDatabaseHas('case_handovers', ['id' => $handoverId, 'status' => 'accepted']);

    $this->postJson("/api/handovers/{$handoverId}/complete", [], ['Authorization' => 'Bearer '.apiLogin('u8')])->assertOk();
});
