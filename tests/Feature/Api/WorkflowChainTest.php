<?php

use App\Models\Employee;
use App\Models\LeaveDocument;
use App\Models\LeaveRequest;
use App\Models\ProsecutionCase;
use App\Models\WorkflowAssignment;

it('submits a draft and advances a full approval chain to completion', function () {
    // e3 (HR, d1/z1 HQ) has no open court cases and can create requests. Its
    // annual chain: SUPERVISOR = supervisor e1; HR step would be self-approval
    // so it escalates to the final authority e1.
    $token = apiLogin('u3');
    $adminToken = apiLogin('u1');
    $start = now()->addDays(30)->toDateString();
    $end = now()->addDays(34)->toDateString();

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => $start,
        'end_date' => $end,
        'reason' => 'Annual leave — planned well in advance.',
    ], ['Authorization' => "Bearer {$token}"]);

    $created->assertStatus(201)->assertJsonPath('data.status', 'draft');
    $requestId = $created->json('data.id');

    $submitted = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$token}"]);
    $submitted->assertOk()->assertJsonPath('data.status', 'under_review');

    // HQ annual <=20 days: SUPERVISOR -> ZONAL_HEAD -> HR, no final sign-off.
    // ZONAL_HEAD is present for HQ staff too. It used to be skipped for HQ
    // zones, which meant an HQ employee's leave never got regional review; that
    // bypass was removed so every zone resolves the same chain. e3 holds HR
    // itself, so the HR step self-escalates to the final authority e1.
    $roles = collect($submitted->json('workflow'))->pluck('role')->all();
    expect($roles)->toBe(['SUPERVISOR', 'ZONAL_HEAD', 'HR']);
    $assignees = collect($submitted->json('workflow'))->pluck('assignee_id')->all();
    expect($assignees)->toBe(['e1', 'e8', 'e1']);
    expect($submitted->json('workflow')[2]['via'])->toBe('escalated');

    // request dates are materialised for the absence calendar
    $this->assertDatabaseHas('leave_request_dates', ['request_id' => $requestId, 'the_date' => $start]);

    // Step 1 approved by e1 -> advances to step 2 (ZONAL_HEAD, delegated to e8)
    $this->postJson("/api/requests/{$requestId}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$adminToken}"])
        ->assertOk()
        ->assertJsonPath('blocked_by_handover', false);

    $next = WorkflowAssignment::where('request_id', $requestId)->where('status', 'pending')->orderBy('id')->first();
    expect($next->assigned_role_id)->toBe('ZONAL_HEAD');
    expect($next->assigned_employee_id)->toBe('e8');

    // The delegated regional sign-off cannot be skipped by the DPP: e1 still
    // holds the later HR step, but the chain head is not theirs to decide.
    $this->postJson("/api/requests/{$requestId}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$adminToken}"])
        ->assertStatus(422)
        ->assertJsonPath('errors.authorization.0', 'This step is not assigned to you — you cannot decide it.');

    $zonalToken = apiLogin('u8');
    $this->postJson("/api/requests/{$requestId}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$zonalToken}"])
        ->assertOk();

    // Step 3 (HR, escalated to e1) approved -> no open court cases for e3 -> fully approved
    $this->postJson("/api/requests/{$requestId}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$adminToken}"])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
    expect(LeaveRequest::find($requestId)->current_owner_id)->toBe('e3');

    // Ledger stays tamper-evident after the two decisions
    $this->getJson('/api/audit/verify', ['Authorization' => "Bearer {$adminToken}"])->assertJsonPath('data.valid', true);
});

it('routes a delegated DEPT_HEAD step to the delegate over the standing holder', function () {
    // e1 (d2/z1) requests Leave-Without-Pay: SUPERVISOR step is skipped (no
    // reporting line above the DPP), DEPT_HEAD (d2, standing holder = e6) is
    // overridden by active delegation del1 -> e7, then HR e3.
    $dppToken = apiLogin('u1');
    $start = now()->addDays(30)->toDateString();
    $end = now()->addDays(39)->toDateString();

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt7',
        'start_date' => $start,
        'end_date' => $end,
        'reason' => 'Personal matters requiring extended absence.',
    ], ['Authorization' => "Bearer {$dppToken}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    $submitted = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$dppToken}"]);
    $submitted->assertOk();

    $workflow = $submitted->json('workflow');
    expect(collect($workflow)->pluck('role')->all())->toBe(['DEPT_HEAD', 'HR']);
    expect(collect($workflow)->pluck('assignee_id')->all())->toBe(['e7', 'e3']);
    expect($workflow[0]['via'])->toBe('delegation');

    // The delegate (e7) owns the step and can advance it
    $delegateToken = apiLogin('u7');
    $this->postJson("/api/requests/{$requestId}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$delegateToken}"])
        ->assertOk()
        ->assertJsonPath('data.current_owner_id', 'e3');
});

it('never bounces a delegation back to the requester (self-exclusion fallback)', function () {
    // e7 is in d2 (DEPT_HEAD standing holder e6); del1 tries to delegate the
    // role to e7 herself for maternity — that would be self-approval, so the
    // system must fall back to the standing holder e6. Maternity also requires
    // the antenatal record on file before submission.
    $requesterToken = apiLogin('u7');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt3',
        'start_date' => '2031-10-01',
        'end_date' => '2031-10-15',
        'reason' => 'Statutory maternity leave.',
    ], ['Authorization' => "Bearer {$requesterToken}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    // e7 (SUPERVISOR) lacks documents.create, so the required antenatal record
    // is placed on the file directly — the chain resolution is what we assert.
    LeaveDocument::create([
        'request_id' => $requestId,
        'file_ref' => 'documents/antenatal_e7.pdf',
        'document_type' => 'antenatal_record',
        'name' => 'antenatal_e7.pdf',
        'size' => '512 KB',
        'uploaded_by_user_id' => 'u7',
        'uploaded_at' => now(),
    ]);

    $submitted = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requesterToken}"]);
    $submitted->assertOk();

    // Maternity always ends with FINAL_AUTHORITY; DEPT_HEAD falls back to e6
    expect(collect($submitted->json('workflow'))->pluck('role')->all())
        ->toBe(['DEPT_HEAD', 'HR', 'FINAL_AUTHORITY']);
    expect($submitted->json('workflow')[0]['assignee_id'])->toBe('e6');
});

it('pauses final approval on the handover gate and resumes once handovers are accepted', function () {
    $adminToken = apiLogin('u1'); // e1 = current FINAL_AUTHORITY owner of r3
    $recipientToken = apiLogin('u8'); // e8 accepts coverage of e9's court case

    $r3 = LeaveRequest::where('ref', 'LVE-2026-0153')->first();
    expect($r3->status)->toBe('under_review');

    // FINAL authority approval attempt -> blocked by e9's active court case
    $blocked = $this->postJson("/api/requests/{$r3->id}/decide", [
        'decision' => 'approved',
        'comments' => 'Statutory maternity sign-off.',
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => "Bearer {$adminToken}"]);
    $blocked->assertOk()->assertJsonPath('blocked_by_handover', true);
    expect(LeaveRequest::find($r3->id)->status)->toBe('under_review');
    expect(LeaveRequest::find($r3->id)->current_owner_id)->toBeNull();

    // The attempt rolls back to pending — a retry is possible without stepping twice
    $rollback = WorkflowAssignment::where('request_id', $r3->id)->where('status', 'pending')->orderBy('id')->first();
    expect($rollback->assigned_role_id)->toBe('FINAL_AUTHORITY');

    // Propose handover of CR-2026-0142 to e8, then have e8 accept it
    $proposed = $this->postJson("/api/handovers/{$r3->id}/propose", ['to_employee_id' => 'e8'], ['Authorization' => "Bearer {$adminToken}"]);
    $proposed->assertStatus(201);
    $handoverId = $proposed->json('data.0.id');

    $this->postJson("/api/handovers/{$handoverId}/accept", [], ['Authorization' => "Bearer {$recipientToken}"])->assertOk();
    $this->assertDatabaseHas('case_handovers', ['id' => $handoverId, 'status' => 'accepted']);

    // The final authority can now re-approve; the request completes
    $final = $this->postJson("/api/requests/{$r3->id}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$adminToken}"]);
    $final->assertOk()->assertJsonPath('data.status', 'approved');
    expect(ProsecutionCase::where('ref', 'CR-2026-0142')->first()->owner_id)->toBe('e8');

    $this->getJson('/api/audit/verify', ['Authorization' => "Bearer {$adminToken}"])->assertJsonPath('data.valid', true);
});

it('returns a request for correction and lets the requester resubmit without losing history', function () {
    // Fresh 2-day sick leave for e7 (notice window is 1 day). SUPERVISOR e6
    // returns it, the requester resubmits, and the completed step is preserved.
    $requester = apiLogin('u7');
    $supervisor = apiLogin('u6');
    $hr = apiLogin('u3');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt2',
        'start_date' => '2031-09-20',
        'end_date' => '2031-09-21',
        'reason' => 'Malaria treatment.',
    ], ['Authorization' => "Bearer {$requester}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    // Sick leave requires the medical certificate before the first submit
    LeaveDocument::create([
        'request_id' => $requestId,
        'file_ref' => 'documents/cert_e7.pdf',
        'document_type' => 'Medical certificate',
        'name' => 'cert_e7.pdf',
        'size' => '96 KB',
        'uploaded_by_user_id' => 'u7',
        'uploaded_at' => now(),
    ]);

    $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requester}"])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');

    // e6 (SUPERVISOR) returns it for correction
    $this->postJson("/api/requests/{$requestId}/decide", [
        'decision' => 'returned',
        'comments' => 'Please state the date range of the certification.',
    ], ['Authorization' => "Bearer {$supervisor}"])
        ->assertOk()
        ->assertJsonPath('data.status', 'returned_for_correction');

    // Resubmission restores the pending step without duplicating the done one
    $this->postJson("/api/requests/{$requestId}/resubmit", [], ['Authorization' => "Bearer {$requester}"])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');

    $this->assertDatabaseHas('workflow_assignments', ['request_id' => $requestId, 'assigned_role_id' => 'SUPERVISOR', 'status' => 'done']);
    $this->assertDatabaseHas('workflow_assignments', ['request_id' => $requestId, 'assigned_role_id' => 'HR', 'status' => 'pending']);
    expect(LeaveRequest::find($requestId)->current_owner_id)->toBe('e3');
});

it('withdraws a request owned by the requester', function () {
    $requester = apiLogin('u7');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-20',
        'end_date' => '2026-11-24',
        'reason' => 'Planned break.',
    ], ['Authorization' => "Bearer {$requester}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$requester}"])->assertOk();
    $this->postJson("/api/requests/{$requestId}/withdraw", [], ['Authorization' => "Bearer {$requester}"])->assertOk();
    $this->assertDatabaseHas('leave_requests', ['id' => $requestId, 'status' => 'withdrawn']);
});

it('rejects decisions from employees not assigned to the current step', function () {
    $token = apiLogin('u11'); // e11 (Records) — not on any step of r2
    $r2 = LeaveRequest::where('ref', 'LVE-2026-0150')->first();

    $this->postJson("/api/requests/{$r2->id}/decide", ['decision' => 'approved', 'signature_hash' => str_repeat('a', 64)], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422);
});
