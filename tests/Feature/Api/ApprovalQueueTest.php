<?php

use App\Models\LeaveRequest;
use App\Models\WorkflowAssignment;

function submitAnnualForQueue(): int
{
    $token = apiLogin('u3');
    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(60)->toDateString(),
        'end_date' => now()->addDays(63)->toDateString(),
        'reason' => 'Queue ordering check.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $id = $created->json('data.id');
    test()->postJson("/api/requests/{$id}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    return (int) $id;
}

it('keeps only the current step pending and queues the rest', function () {
    $requestId = submitAnnualForQueue();

    $statuses = WorkflowAssignment::where('request_id', $requestId)->orderBy('id')->pluck('status')->all();
    expect($statuses)->toBe(['pending', 'queued', 'queued']);
    expect(LeaveRequest::find($requestId)->current_owner_id)->toBe('e1');
});

it('shows a request only to its current approver, not to queued approvers', function () {
    $requestId = submitAnnualForQueue();
    $ref = LeaveRequest::find($requestId)->ref;

    $zonalInbox = test()->getJson('/api/requests/inbox', ['Authorization' => 'Bearer '.apiLogin('u8')])
        ->assertOk()->json('data');
    expect(collect($zonalInbox)->pluck('request_ref')->all())->not->toContain($ref);

    $supervisorInbox = test()->getJson('/api/requests/inbox', ['Authorization' => 'Bearer '.apiLogin('u1')])
        ->assertOk()->json('data');
    expect(collect($supervisorInbox)->pluck('request_ref')->all())->toContain($ref);
});

it('reserves the return date and granted days for the final approver', function () {
    $requestId = submitAnnualForQueue();

    test()->postJson("/api/requests/{$requestId}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('a', 64),
        'end_date' => now()->addDays(63)->toDateString(),
    ], ['Authorization' => 'Bearer '.apiLogin('u1')])
        ->assertStatus(422)
        ->assertJsonPath('errors.end_date.0', 'Ni mwidhinishaji wa mwisho pekee anayeweza kuweka tarehe ya kurudi na muda wa likizo.');

    expect(WorkflowAssignment::where('request_id', $requestId)->where('status', 'pending')->count())->toBe(1);
});

it('moves the next queued step to pending once the current step is decided', function () {
    $requestId = submitAnnualForQueue();

    test()->postJson("/api/requests/{$requestId}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('a', 64),
    ], ['Authorization' => 'Bearer '.apiLogin('u1')])->assertOk();

    $statuses = WorkflowAssignment::where('request_id', $requestId)->orderBy('id')->pluck('status')->all();
    expect($statuses)->toBe(['done', 'pending', 'queued']);
    expect(LeaveRequest::find($requestId)->current_owner_id)->toBe('e8');
});
