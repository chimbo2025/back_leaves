<?php

use App\Models\ApprovalSignature;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Services\LeaveApprovalSignatureService;
use Illuminate\Support\Facades\Cache;

/**
 * Decide-time authorization: capability AND ownership, plus the approval
 * ceremony attestation.
 *
 * - Assignment ownership comes first (a stranger still hears "not assigned",
 *   422), then the decision-specific code (approve/reject/return).
 * - Approving without the signature ceremony attestation is rejected (422),
 *   so the Approvals modal cannot be skipped by calling the API directly.
 * - Every surviving decision is sealed server-side (tamper-evident hash
 *   recomputed from the canonical payload); blocked/rolled-back decisions
 *   seal nothing.
 * - An active, in-window delegation for the step's role satisfies capability
 *   (the delegation was authorized at creation), but never the ceremony.
 */
function decisionRequest(string $username = 'u7'): LeaveRequest
{
    $token = apiLogin($username);

    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Annual leave — decision code fixture.',
    ], ['Authorization' => "Bearer {$token}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    test()->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$token}"])->assertOk();

    return LeaveRequest::find($requestId);
}

it('requires the decision code on top of assignment ownership', function () {
    $request = decisionRequest('u7');

    // e6 holds the pending SUPERVISOR step via an unscoped SUPERVISOR grant
    // plus a department-scoped DEPT_HEAD grant. The API revokes the first;
    // scoped grants are managed on the employee record, so the second is
    // deactivated directly (mirroring LifecycleRegressionTest). Either way
    // the assignment row is untouched, so ownership still passes and only
    // the matrix denies.
    $hr = apiLogin('u3');
    $this->deleteJson('/api/org/employees/e6/roles/SUPERVISOR', [], ['Authorization' => "Bearer {$hr}"])->assertOk();
    EmployeeRole::where('employee_id', 'e6')->where('role_id', 'DEPT_HEAD')->update(['active' => false]);
    Cache::flush();

    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'rejected',
        'comments' => 'No cover available.',
    ], ['Authorization' => 'Bearer '.apiLogin('u6')])
        ->assertForbidden()
        ->assertJsonPath('message', 'Missing permission: requests.reject.');
});

it('requires a written reason to return or reject', function () {
    $request = decisionRequest('u7');
    $token = apiLogin('u6');

    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'returned',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('comments');

    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'rejected',
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('comments');

    // The reason survives on the chain and is readable by the requester.
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'rejected',
        'comments' => 'No cover available for these dates.',
    ], ['Authorization' => "Bearer {$token}"])->assertOk();

    $requesterView = $this->getJson("/api/requests/{$request->id}", [
        'Authorization' => 'Bearer '.apiLogin('u7'),
    ])->assertOk();

    $comment = collect($requesterView->json('assignments'))
        ->firstWhere('decision', 'rejected')['decision_comment'] ?? null;

    expect($comment)->toBe('No cover available for these dates.');

    // The list payload (what the requester's table/detail modal reads) carries
    // the same reason.
    $list = $this->getJson('/api/requests?scope=all&per_page=100', [
        'Authorization' => 'Bearer '.apiLogin('u7'),
    ])->assertOk();

    $row = collect($list->json('data.data'))->firstWhere('id', $request->id);
    $listed = collect($row['assignments'] ?? [])->firstWhere('decision', 'rejected');

    expect($listed['decision_comment'] ?? null)->toBe('No cover available for these dates.');
});

it('hides approval commentary from the requester but keeps it for approvers', function () {
    $request = decisionRequest('u7');
    $sig = str_repeat('d', 64);

    // Step 1 approves with a private note.
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'approved',
        'comments' => 'Internal note: watch the handover dates.',
        'signature_hash' => $sig,
    ], ['Authorization' => 'Bearer '.apiLogin('u6')])->assertOk();

    $requesterComments = collect(
        $this->getJson("/api/requests/{$request->id}", ['Authorization' => 'Bearer '.apiLogin('u7')])
            ->assertOk()
            ->json('assignments'),
    )->pluck('decision_comment')->filter()->values();

    expect($requesterComments)->toBeEmpty();

    $approverComments = collect(
        $this->getJson("/api/requests/{$request->id}", ['Authorization' => 'Bearer '.apiLogin('u3')])
            ->assertOk()
            ->json('assignments'),
    )->pluck('decision_comment')->filter()->values();

    expect($approverComments->all())->toContain('Internal note: watch the handover dates.');

    // Same on the list endpoint an approver actually reads.
    $approverList = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => 'Bearer '.apiLogin('u3')])
        ->assertOk();
    $listedRow = collect($approverList->json('data.data'))->firstWhere('id', $request->id);
    $listedComments = collect($listedRow['assignments'] ?? [])->pluck('decision_comment')->filter()->values();

    expect($listedComments->all())->toContain('Internal note: watch the handover dates.');
});

it('refuses signature-less approvals', function () {
    $request = decisionRequest('u7');

    // e6 is the assignee and holds requests.approve: only the ceremony is
    // missing, so this must fail validation, not authorization.
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'approved',
    ], ['Authorization' => 'Bearer '.apiLogin('u6')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('signature_hash');

    expect(ApprovalSignature::where('leave_request_id', $request->id)->count())->toBe(0);
});

it('seals every surviving decision server-side', function () {
    $request = decisionRequest('u7');
    $sig = str_repeat('b', 64);

    // Step 1 (SUPERVISOR e6) approves with ceremony.
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'approved',
        'signature_hash' => $sig,
    ], ['Authorization' => 'Bearer '.apiLogin('u6')])->assertOk();

    $seal = ApprovalSignature::where('leave_request_id', $request->id)->where('approval_action', 'approved')->firstOrFail();
    expect($seal->signature_value)->toBe($sig)
        ->and($seal->actor_id)->toBe('e6')
        ->and(app(LeaveApprovalSignatureService::class)->verify($seal))->toBeTrue();

    // Whoever holds the next step rejects with no client attestation. The
    // decision is still sealed — the server hash needs no client input.
    // (Every approver role holds requests.reject, so any assignee qualifies.)
    $next = $request->assignments()->where('status', 'pending')->orderBy('id')->firstOrFail();
    $assigneeUser = 'u'.substr($next->assigned_employee_id, 1);
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'rejected',
        'comments' => 'Blackout period.',
    ], ['Authorization' => 'Bearer '.apiLogin($assigneeUser)])->assertOk();

    $rejectionSeal = ApprovalSignature::where('leave_request_id', $request->id)->where('approval_action', 'rejected')->firstOrFail();
    expect($rejectionSeal->signature_value)->toBeNull()
        ->and(app(LeaveApprovalSignatureService::class)->verify($rejectionSeal))->toBeTrue();

    expect(ApprovalSignature::where('leave_request_id', $request->id)->count())->toBe(2);
});

it('lets an active delegate decide without role codes, but never skip the ceremony', function () {
    // e6 delegates SUPERVISOR to e11 (plain employee, SELF_SERVICE only).
    $this->postJson('/api/delegations', [
        'role_id' => 'SUPERVISOR',
        'delegate_employee_id' => 'e11',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
        'reason' => 'Cover while away.',
    ], ['Authorization' => 'Bearer '.apiLogin('u6')])->assertCreated();

    $request = decisionRequest('u7');

    // The step resolved to the delegate, who holds no requests.approve.
    expect($request->assignments()->where('status', 'pending')->orderBy('id')->first()->assigned_employee_id)->toBe('e11');

    // Without the ceremony even the delegate is refused.
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'approved',
    ], ['Authorization' => 'Bearer '.apiLogin('u11')])->assertStatus(422);

    // With it, the delegation satisfies capability.
    $this->postJson("/api/requests/{$request->id}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('c', 64),
    ], ['Authorization' => 'Bearer '.apiLogin('u11')])->assertOk();

    expect(ApprovalSignature::where('leave_request_id', $request->id)->where('actor_id', 'e11')->count())->toBe(1);
});
