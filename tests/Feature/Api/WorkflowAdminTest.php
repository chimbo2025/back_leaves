<?php

use App\Models\WorkflowDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Workflow definition lifecycle — create, activate/deactivate, delete.
 *
 * Doctrine: changes apply to new submissions only (assignments snapshot at
 * submit), history stays locked (a definition carrying assignments can only
 * be deactivated, never deleted), and a leave type always keeps at least one
 * active chain so submissions keep resolving. Deletion rides the stacked
 * policies.edit_policy + approval_workflows.update codes — update-class,
 * no dedicated delete permission code.
 */
it('creates a new version, toggles the old one off, and routes new submissions through the new chain', function () {
    $admin = apiLogin('u1');

    $created = $this->postJson('/api/workflows', [
        'leave_type_id' => 'lt1',
        'min_duration_days' => 0,
        'steps' => [
            ['role_required' => 'ZONAL_HEAD', 'sla_days' => 2, 'note' => 'Regional verifies.'],
            ['role_required' => 'HR', 'sla_days' => 1, 'note' => 'HR compliance.'],
        ],
    ], ['Authorization' => "Bearer {$admin}"])
        ->assertStatus(201)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.is_active', true);
    $newId = $created->json('data.id');

    // Toggle the seeded v1 off — allowed because v2 is active.
    $this->patchJson('/api/workflows/wf_annual', ['is_active' => false], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // New submissions resolve exclusively through the active definition.
    $token = apiLogin('u3');
    $req = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Should route via the new chain.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201)->json('data');

    $submitted = $this->postJson("/api/requests/{$req['id']}/submit", [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    // The new definition's two-step chain replaces the deactivated v1's
    // three-step one: regional review comes back, and e3's HR step
    // self-escalates to the final authority e1.
    $workflow = $submitted->json('workflow');
    expect(collect($workflow)->pluck('role')->all())->toBe(['ZONAL_HEAD', 'HR']);
    expect(collect($workflow)->pluck('assignee_id')->all())->toBe(['e8', 'e1']);
    expect($workflow[1]['via'])->toBe('escalated');
    // The chain was built from the new definition's own steps.
    $newStepIds = DB::table('workflow_steps')->where('workflow_def_id', $newId)->orderBy('step_order')->pluck('id')->all();
    expect(collect($workflow)->pluck('step_id')->all())->toBe($newStepIds);
});

it('refuses deactivating the last active workflow of a leave type', function () {
    $admin = apiLogin('u1');

    $this->patchJson('/api/workflows/wf_annual', ['is_active' => false], ['Authorization' => "Bearer {$admin}"])
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_active.0', 'A leave type must keep at least one active workflow — create its replacement first.');

    expect(WorkflowDefinition::find('wf_annual')->is_active)->toBeTrue();
});

it('deletes an unused definition and records the audit entry', function () {
    $admin = apiLogin('u1');

    $created = $this->postJson('/api/workflows', [
        'leave_type_id' => 'lt1',
        'min_duration_days' => 10,
        'steps' => [['role_required' => 'DEPT_HEAD', 'sla_days' => 2, 'note' => 'Long-stay check.']],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    $this->deleteJson("/api/workflows/{$created['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('message', 'Workflow deleted.');

    expect(WorkflowDefinition::find($created['id']))->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['entity_type' => 'workflow', 'entity_id' => $created['id'], 'action' => 'delete']);
});

it('refuses deleting a definition whose steps carry assignment history', function () {
    $admin = apiLogin('u1');
    $token = apiLogin('u3');

    // Give wf_annual real history: a submitted request snapshots assignments
    // onto its steps.
    $req = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Creates assignment history.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201)->json('data');
    $this->postJson("/api/requests/{$req['id']}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    $this->deleteJson('/api/workflows/wf_annual', [], ['Authorization' => "Bearer {$admin}"])
        ->assertUnprocessable();

    expect(WorkflowDefinition::find('wf_annual'))->not->toBeNull();
});

it('refuses deleting the last active workflow of a leave type', function () {
    $admin = apiLogin('u1');

    // Start from a type with exactly one definition, whatever the seed holds.
    DB::table('workflow_steps')->whereIn('workflow_def_id', DB::table('workflow_definitions')->where('leave_type_id', 'lt2')->pluck('id'))->delete();
    DB::table('workflow_definitions')->where('leave_type_id', 'lt2')->delete();

    $created = $this->postJson('/api/workflows', [
        'leave_type_id' => 'lt2',
        'steps' => [['role_required' => 'SUPERVISOR', 'sla_days' => 1, 'note' => 'Sole chain.']],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    $this->deleteJson("/api/workflows/{$created['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_active.0', 'A leave type must keep at least one active workflow — create its replacement first.');

    expect(WorkflowDefinition::find($created['id']))->not->toBeNull();
});

it('refuses workflow administration to employees without the codes', function () {
    $token = apiLogin('u9'); // e9 — SELF_SERVICE floor only

    $this->deleteJson('/api/workflows/wf_annual', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);
    $this->patchJson('/api/workflows/wf_annual', ['is_active' => false], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);

    expect(WorkflowDefinition::find('wf_annual')->is_active)->toBeTrue();
});
