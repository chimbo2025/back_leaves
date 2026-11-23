<?php

use App\Models\LeaveRequest;

/**
 * HR is the last step of the annual-leave chain. Their decision carries the
 * official reference, the granted-day override and the response-letter body;
 * these must survive on the request so the employee can re-open the letter.
 */
it('persists the HR response letter on final approval', function () {
    // e7 (u7) requests leave; HR (e3) is the final step. Using a different
    // employee keeps the self-approval guard out of the way.
    $token = apiLogin('u7');
    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(60)->toDateString(),
        'end_date' => now()->addDays(60)->toDateString(),
        'reason' => 'Response letter persistence check.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $id = (int) $created->json('data.id');
    test()->postJson("/api/requests/{$id}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    $request = LeaveRequest::findOrFail($id);
    $assignments = $request->assignments()->orderBy('id')->get();
    $final = $assignments->last();

    // Close every earlier step and put HR at the head of the chain. This
    // isolates the final-approval persistence path from chain ordering.
    foreach ($assignments as $assignment) {
        $assignment->update([
            'status' => $assignment->id === $final->id ? 'pending' : 'done',
            'decided_at' => $assignment->id === $final->id ? null : now(),
        ]);
    }
    $final->update(['assigned_employee_id' => 'e3', 'assigned_role_id' => 'HR']);
    $request->update(['status' => 'under_review', 'current_owner_id' => 'e3']);

    $body = 'Ombi lako la likizo ya mwaka limeidhinishwa. Kipindi cha likizo: 01 Januari 2027 (siku 1).';

    $response = test()->postJson("/api/requests/{$id}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('b', 64),
        'end_date' => now()->addDays(60)->toDateString(),
        'reference_number' => 'Kumb. Na. DPP/ZNZ/HR/2026/014',
        'approved_days' => 1,
        'letter_body' => $body,
        'is_paid' => true,
    ], ['Authorization' => 'Bearer '.apiLogin('u3')])->assertOk();

    $response->assertJsonPath('data.reference_number', 'Kumb. Na. DPP/ZNZ/HR/2026/014');
    $response->assertJsonPath('data.approved_days', 1);
    $response->assertJsonPath('data.letter_body', $body);
    $response->assertJsonPath('data.is_paid', true);

    $fresh = LeaveRequest::findOrFail($id);
    expect($fresh->status)->toBe('approved');
    expect($fresh->reference_number)->toBe('Kumb. Na. DPP/ZNZ/HR/2026/014');
    expect((int) $fresh->approved_days)->toBe(1);
    expect($fresh->letter_body)->toBe($body);
    expect($fresh->letter_issued_at)->not->toBeNull();
    expect((bool) $fresh->is_paid)->toBeTrue();
});

it('does not write letter fields on an intermediate approval', function () {
    $token = apiLogin('u7');
    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(60)->toDateString(),
        'end_date' => now()->addDays(63)->toDateString(),
        'reason' => 'Intermediate approval guard check.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $id = (int) $created->json('data.id');
    test()->postJson("/api/requests/{$id}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    $request = LeaveRequest::findOrFail($id);
    $first = $request->assignments()->orderBy('id')->first();

    test()->postJson("/api/requests/{$id}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('c', 64),
        'reference_number' => 'SHOULD-NOT-PERSIST',
        'approved_days' => 2,
        'letter_body' => 'SHOULD-NOT-PERSIST',
    ], ['Authorization' => 'Bearer '.apiLogin($first->assignee->user_id)])->assertOk();

    $fresh = LeaveRequest::findOrFail($id);
    expect($fresh->reference_number)->toBeNull();
    expect($fresh->approved_days)->toBeNull();
    expect($fresh->letter_body)->toBeNull();
});
