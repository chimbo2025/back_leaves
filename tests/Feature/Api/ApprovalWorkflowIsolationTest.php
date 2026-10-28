<?php

use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;

/**
 * Workflow configuration lives behind its own module.
 *
 * Reads need approval_workflows.view (stacked with the legacy policies.view
 * so existing readers keep access); writes need approval_workflows.create /
 * update stacked with policies.edit_policy. An ordinary approver
 * (SUPERVISOR: policies.view but no workflow codes) can look but never
 * change the chains, and neither legacy code alone opens a write.
 */
function grantWorkflowCode(string $code, string $employeeId = 'e11'): void
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

it('lets workflow readers look but never change the chains', function () {
    // u6 supervises but holds no workflow codes: reads pass, writes 403.
    $supervisor = apiLogin('u6');

    $this->getJson('/api/workflows', ['Authorization' => "Bearer {$supervisor}"])->assertOk();
    $this->postJson('/api/workflows', [], ['Authorization' => "Bearer {$supervisor}"])->assertForbidden();
});

it('requires both the legacy and the dedicated code for workflow writes', function () {
    // e11 keeps SELF_SERVICE (group gate policies.view passes), so each
    // assertion below isolates exactly one side of the stacked pair.
    grantWorkflowCode('policies.edit_policy', 'e11');
    $this->postJson('/api/workflows', [], ['Authorization' => 'Bearer '.apiLogin('u11')])->assertForbidden();

    // Dedicated code alone is not enough either: drop the legacy grant and
    // keep only the dedicated one.
    $role = Role::where('id', 'PROBE_POLICIES_EDIT_POLICY')->firstOrFail();
    $role->permissions()->detach(Permission::where('code', 'policies.edit_policy')->value('id'));
    grantWorkflowCode('approval_workflows.create', 'e11');
    $this->postJson('/api/workflows', [], ['Authorization' => 'Bearer '.apiLogin('u11')])->assertForbidden();

    // The pair opens the endpoint (422: gates passed, payload empty).
    $role->permissions()->attach(Permission::where('code', 'policies.edit_policy')->value('id'));
    Cache::flush();
    $this->postJson('/api/workflows', [], ['Authorization' => 'Bearer '.apiLogin('u11')])->assertStatus(422);
});

it('requires both view codes for workflow reads', function () {
    $hr = apiLogin('u3');
    $this->deleteJson('/api/org/employees/e11/roles/SELF_SERVICE', [], ['Authorization' => "Bearer {$hr}"])->assertOk();

    grantWorkflowCode('approval_workflows.view', 'e11');
    // policies.view missing: still closed.
    $this->getJson('/api/workflows', ['Authorization' => 'Bearer '.apiLogin('u11')])->assertForbidden();
});
