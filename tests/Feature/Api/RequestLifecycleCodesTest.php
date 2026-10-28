<?php

use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Cache;

/**
 * Request lifecycle actions carry their own codes.
 *
 * submit/resubmit need requests.submit, withdraw needs requests.withdraw,
 * draft edits need requests.edit_own, and even viewing your own request
 * needs requests.view_own — all granted to every active employee through
 * SELF_SERVICE, so ordinary staff notice nothing, but every switch in the
 * matrix now means something and revoking the floor closes the lifecycle.
 */
it('closes the whole lifecycle when the self-service floor is revoked', function () {
    // e11 is a true plain employee (SELF_SERVICE only): unlike e7, no
    // approver role survives the floor revocation to muddy the denial.
    $ownerToken = apiLogin('u11');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-20',
        'end_date' => '2026-11-24',
        'reason' => 'Planned break.',
    ], ['Authorization' => "Bearer {$ownerToken}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');

    // Revoke the floor. The owner token stays valid (Sanctum does not tie
    // tokens to grants), so every denial below also proves a stale frontend
    // capability still meets a backend denial.
    $this->deleteJson('/api/org/employees/e11/roles/SELF_SERVICE', [], ['Authorization' => 'Bearer '.apiLogin('u3')])->assertOk();

    $headers = ['Authorization' => "Bearer {$ownerToken}"];
    expect($this->postJson("/api/requests/{$requestId}/submit", [], $headers)->status())->toBe(403);
    expect($this->postJson("/api/requests/{$requestId}/withdraw", [], $headers)->status())->toBe(403);
    expect($this->putJson("/api/requests/{$requestId}", [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-20',
        'end_date' => '2026-11-25',
        'reason' => 'Planned break, extended.',
    ], $headers)->status())->toBe(403);
    expect($this->getJson("/api/requests/{$requestId}", $headers)->status())->toBe(403);
});

it('shows the inbox only while a step is currently assigned', function () {
    $ownerToken = apiLogin('u7');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-10',
        'end_date' => '2026-11-14',
        'reason' => 'Annual leave — inbox fixture.',
    ], ['Authorization' => "Bearer {$ownerToken}"]);
    $created->assertStatus(201);
    $requestId = $created->json('data.id');
    $ref = $created->json('data.ref');
    $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$ownerToken}"])->assertOk();

    $inbox = fn (string $token) => collect(
        $this->getJson('/api/requests/inbox', ['Authorization' => "Bearer {$token}"])->json('data')
    )->pluck('request_ref')->all();

    // Head of chain is the SUPERVISOR step for e7's supervisor e6.
    expect($inbox(apiLogin('u6')))->toContain($ref);
    // Nobody else: not the requester, not an unrelated employee.
    expect($inbox($ownerToken))->not->toContain($ref);
    expect($inbox(apiLogin('u11')))->not->toContain($ref);

    // After e6 approves (with ceremony), the row leaves their inbox.
    $this->postJson("/api/requests/{$requestId}/decide", [
        'decision' => 'approved',
        'signature_hash' => str_repeat('d', 64),
    ], ['Authorization' => 'Bearer '.apiLogin('u6')])->assertOk();
    expect($inbox(apiLogin('u6')))->not->toContain($ref);
});

it('invalidates cached capabilities the moment the matrix changes', function () {
    // Warm the memoised set of a genuine SUPERVISOR holder (u6): a matrix
    // edit must drop holders' sets, and u8 holds no SUPERVISOR so warming it
    // would prove nothing.
    $perms = app(PermissionService::class);
    $user = User::where('username', 'u6')->firstOrFail();

    $perms->codesFor($user);
    expect(Cache::has('perms:u6'))->toBeTrue();

    // A matrix edit (not just a grant) must drop every holder's memoised set.
    $this->patchJson('/api/roles/SUPERVISOR', ['name' => 'Immediate Supervisor'], ['Authorization' => 'Bearer '.apiLogin('u3')])->assertOk();

    expect(Cache::has('perms:u6'))->toBeFalse();
});
