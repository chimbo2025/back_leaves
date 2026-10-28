<?php

/**
 * POST /requests/preview — the approval-chain panel on the new-request form.
 *
 * Resolution is the submission resolver itself (WorkflowResolver), so the
 * parity tests below assert the exact chains WorkflowChainTest sees from a
 * real submit for the same inputs. The access rule mirrors the scope branch
 * of LeaveRequestPolicy::view: scope grants reveal approvers, ownership
 * does not — a plain SELF_SERVICE requester must not read their own chain.
 */
it('previews exactly the chain submission will persist (HQ parity)', function () {
    $token = apiLogin('u1');

    $preview = $this->postJson('/api/requests/preview', [
        'employee_id' => 'e3',
        'leave_type_id' => 'lt1',
        'days' => 5,
    ], ['Authorization' => "Bearer {$token}"])->assertOk();

    // Identical to WorkflowChainTest's submitted chain for the same inputs:
    // HQ keeps its ZONAL_HEAD step, and e3's HR step self-escalates to the
    // final authority instead of approving its own request.
    $chain = $preview->json('data');
    expect(collect($chain)->pluck('role')->all())->toBe(['SUPERVISOR', 'ZONAL_HEAD', 'HR']);
    expect(collect($chain)->pluck('assignee_id')->all())->toBe(['e1', 'e8', 'e1']);
    expect($chain[2]['via'])->toBe('escalated');
});

it('previews delegation overrides the same way submission resolves them', function () {
    $token = apiLogin('u1');

    $chain = $this->postJson('/api/requests/preview', [
        'employee_id' => 'e1',
        'leave_type_id' => 'lt7',
        'days' => 10,
    ], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json('data');

    // Same as WorkflowChainTest's real submit: del1 reroutes the DEPT_HEAD
    // step from standing holder e6 to delegate e7.
    expect(collect($chain)->pluck('role')->all())->toBe(['DEPT_HEAD', 'HR']);
    expect(collect($chain)->pluck('assignee_id')->all())->toBe(['e7', 'e3']);
    expect($chain[0]['via'])->toBe('delegation');
});

it('refuses a plain employee the chain of their own request', function () {
    // e7 holds only SUPERVISOR/SELF_SERVICE grants — no zone or department
    // scope — so ownership is all they have, and ownership is not enough.
    $token = apiLogin('u7');

    $this->postJson('/api/requests/preview', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'days' => 5,
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(403);
});

it('scopes the preview to the calling officer grants', function () {
    // u4 (e4) holds scope zone z2 + department d4. HQ staff (z1/d1) are
    // outside that reach and must be refused; staff inside it preview fine.
    $token = apiLogin('u4');

    $this->postJson('/api/requests/preview', [
        'employee_id' => 'e3',
        'leave_type_id' => 'lt1',
        'days' => 5,
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(403);

    $chain = $this->postJson('/api/requests/preview', [
        'employee_id' => 'e8',
        'leave_type_id' => 'lt1',
        'days' => 5,
    ], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json('data');

    expect(collect($chain)->pluck('role')->all())->toContain('SUPERVISOR');
    expect($chain[0]['assignee_id'])->toBe('e4');
});

it('previews an open-ended request the way an end-date-less draft resolves', function () {
    // No days yet (return date is set at approval): the definition lookup
    // must not filter on duration, exactly as submission sees the draft.
    $token = apiLogin('u1');

    $this->postJson('/api/requests/preview', [
        'employee_id' => 'e3',
        'leave_type_id' => 'lt1',
        'days' => null,
    ], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.0.role', 'SUPERVISOR');
});
