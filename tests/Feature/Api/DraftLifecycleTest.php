<?php

use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;

/**
 * Draft lifecycle — retry-submitting an orphaned draft, and TRUE deletion.
 *
 * Doctrine: a request that never entered approval carries no history, so
 * the applicant may hard-delete it (DELETE /requests/{id}); anything that
 * was ever submitted can only be withdrawn — a status change that keeps
 * the row in the audit trail. There is no permission code by design
 * (ownership-intrinsic, same doctrine as the decide route).
 */
it('lets the owner hard-delete their own draft', function () {
    $token = apiLogin('u3');

    $draft = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Scratch draft for delete test.',
    ], ['Authorization' => "Bearer {$token}"])->assertCreated()->json('data');

    expect(LeaveRequest::find($draft['id']))->not->toBeNull();

    $this->deleteJson("/api/requests/{$draft['id']}", [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('message', 'Draft deleted.');

    // Gone for good: the model no longer resolves, and the audit trail
    // still records who removed which ref.
    expect(LeaveRequest::find($draft['id']))->toBeNull();
    $this->deleteJson("/api/requests/{$draft['id']}", [], ['Authorization' => "Bearer {$token}"])
        ->assertNotFound();
    expect(DB::table('audit_logs')->where('action', 'draft_delete')->where('entity_id', (string) $draft['id'])->exists())->toBeTrue();
});

it('refuses deleting someone else\'s draft', function () {
    $owner = apiLogin('u3');
    $draft = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Not yours.',
    ], ['Authorization' => "Bearer {$owner}"])->assertCreated()->json('data');

    // u4 (e4) may even see this draft through their zone scope — visibility
    // never grants deletion: only the applicant may remove their draft.
    $other = apiLogin('u4');
    $this->deleteJson("/api/requests/{$draft['id']}", [], ['Authorization' => "Bearer {$other}"])
        ->assertStatus(403)
        ->assertJsonPath('message', 'You can only delete your own requests.');

    expect(LeaveRequest::find($draft['id']))->not->toBeNull();
});

it('refuses deleting anything that ever entered approval', function () {
    $token = apiLogin('u3');
    $draft = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Submitted, therefore history.',
    ], ['Authorization' => "Bearer {$token}"])->assertCreated()->json('data');

    $this->postJson("/api/requests/{$draft['id']}/submit", [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();
    expect(LeaveRequest::find($draft['id'])->status)->not->toBe('draft');

    $this->deleteJson("/api/requests/{$draft['id']}", [], ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Only draft requests can be deleted. Submitted requests are kept in the audit trail.');

    expect(LeaveRequest::find($draft['id']))->not->toBeNull();
});

it('submits an orphaned draft end-to-end (retry path)', function () {
    // The exact live failure path: create succeeded, submit failed once
    // (eligibility crash), draft stayed behind — the new "Submit for
    // approval" action posts to this same endpoint and must now succeed.
    $token = apiLogin('u3');
    $draft = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'reason' => 'Orphaned draft retry.',
    ], ['Authorization' => "Bearer {$token}"])->assertCreated()->json('data');

    expect($draft['status'])->toBe('draft');

    $res = $this->postJson("/api/requests/{$draft['id']}/submit", [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    expect($res->json('data.status'))->toBe('under_review')
        ->and($res->json('workflow'))->toBeArray()->not->toBeEmpty();
});

it('purges only never-submitted withdrawn rows', function () {
    $base = [
        'employee_id' => 'e3',
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-06',
        'days' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('leave_requests')->insert([
        // Junk: "deleted" before true deletion existed — withdrawn only,
        // never submitted.
        [...$base, 'ref' => 'JUNK-1', 'status' => 'withdrawn', 'submitted_at' => null],
        // Real history: entered approval, then withdrawn — must survive.
        [...$base, 'ref' => 'HIST-1', 'status' => 'withdrawn', 'submitted_at' => now()],
        // A live draft — not withdrawn, untouched by the purge.
        [...$base, 'ref' => 'DRAFT-1', 'status' => 'draft', 'submitted_at' => null],
    ]);

    $migration = require base_path('database/migrations/2026_10_18_100000_purge_never_submitted_withdrawn.php');
    $migration->up();

    expect(DB::table('leave_requests')->where('ref', 'JUNK-1')->exists())->toBeFalse()
        ->and(DB::table('leave_requests')->where('ref', 'HIST-1')->exists())->toBeTrue()
        ->and(DB::table('leave_requests')->where('ref', 'DRAFT-1')->exists())->toBeTrue();
});
