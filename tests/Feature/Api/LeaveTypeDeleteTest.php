<?php

use Illuminate\Support\Facades\DB;

/**
 * DELETE /leave/types/{id} — runtime deletion of an unused leave type.
 *
 * Doctrine: history names its leave type forever (requests, balance
 * adjustments, plans, waivers all refuse the delete with counts); a free
 * type takes its configuration family — policy versions, workflow
 * definitions and steps, balances, payment cases — with it. Gated by
 * admin.delete, same family as the zones/departments/staff destroys.
 */
it('deletes an unused leave type with its policies and workflow', function () {
    $admin = apiLogin('u1');

    $type = $this->postJson('/api/leave/types', [
        'code' => 'PILGRIM',
        'name' => 'Pilgrimage Leave',
        'swahili' => 'Likizo ya Hijja',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    $this->postJson('/api/policies', [
        'leave_type_id' => $type['id'],
        'effective_from' => '2026-01-01',
        'max_days_per_year' => 15,
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201);

    $wf = $this->postJson('/api/workflows', [
        'leave_type_id' => $type['id'],
        'steps' => [['role_required' => 'HR', 'sla_days' => 2, 'note' => 'Hajj compliance.']],
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    $this->deleteJson("/api/leave/types/{$type['id']}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('message', 'Leave type deleted, together with its policies and workflow.');

    // The whole configuration family goes with it.
    expect(DB::table('leave_types')->where('id', $type['id'])->exists())->toBeFalse()
        ->and(DB::table('leave_policies')->where('leave_type_id', $type['id'])->exists())->toBeFalse()
        ->and(DB::table('workflow_definitions')->where('id', $wf['id'])->exists())->toBeFalse()
        ->and(DB::table('workflow_steps')->where('workflow_def_id', $wf['id'])->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', ['entity_type' => 'leave_type', 'entity_id' => $type['id'], 'action' => 'delete']);
});

it('refuses deleting a leave type that carries history', function () {
    $admin = apiLogin('u1');

    // lt1 carries seeded requests — history names its type forever.
    $this->deleteJson('/api/leave/types/lt1', [], ['Authorization' => "Bearer {$admin}"])
        ->assertUnprocessable();

    expect(DB::table('leave_types')->where('id', 'lt1')->exists())->toBeTrue();
});

it('refuses leave-type deletion to employees without admin.delete', function () {
    $token = apiLogin('u9'); // e9 — SELF_SERVICE floor only

    $this->deleteJson('/api/leave/types/lt5', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);

    expect(DB::table('leave_types')->where('id', 'lt5')->exists())->toBeTrue();
});
