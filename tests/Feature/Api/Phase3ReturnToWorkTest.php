<?php

use App\Models\AuditLog;
use App\Models\BalanceTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveReturn;
use App\Services\BalanceTransactionService;
use App\Services\ExpectedReturnDateService;
use App\Services\PolicyService;
use App\Services\ReturnToWorkService;
use Illuminate\Testing\TestResponse;

/**
 * Phase 3 — return-to-work and late-return handling.
 *
 * Covers the expected-date calculation, on-time and late reporting, the
 * explanation gate, scope-aware review, excusal/unauthorised arithmetic, the
 * single idempotent ledger deduction, rollback, historical preservation and
 * authorization failures.
 */
function p3Headers(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/** r1: e7 annual leave, approved 2026-10-05 .. 2026-10-16 (ends Friday). */
function p3Request(): LeaveRequest
{
    return LeaveRequest::where('ref', 'LVE-2026-0147')->firstOrFail();
}

function p3Open(string $token = 'u3'): LeaveReturn
{
    return app(ReturnToWorkService::class)->openForRequest(p3Request(), $token);
}

function p3Report(int $requestId, string $actor, string $actualDate, ?string $explanation = null): TestResponse
{
    return test()->postJson(
        "/api/leave-requests/{$requestId}/return",
        array_filter([
            'actual_return_date' => $actualDate,
            'explanation' => $explanation,
        ], fn ($v) => $v !== null),
        p3Headers(apiLogin($actor)),
    );
}

function p3Review(int $returnId, string $actor, array $payload = []): TestResponse
{
    return test()->postJson(
        "/api/leave-returns/{$returnId}/review",
        $payload,
        p3Headers(apiLogin($actor)),
    );
}

beforeEach(function () {
    config([
        'leave.public_holidays' => [],
        'leave.blackout_periods' => [],
        'leave.staffing_minimums' => [],
    ]);
});

it('computes the expected return date as the next working day and freezes it', function () {
    // r1 leaves end on Friday 2026-10-16; the next working day is Monday 19th.
    $case = p3Open();

    expect($case->expected_return_date->toDateString())->toBe('2026-10-19')
        ->and($case->status)->toBe(LeaveReturn::STATUS_PENDING)
        ->and($case->policy_snapshot['method'])->toBe('next_working_day')
        ->and($case->policy_snapshot['leave_end_date'])->toBe('2026-10-16');
});

it('skips a configured public holiday when calculating the expected return date', function () {
    config(['leave.public_holidays' => [['date' => '2026-10-19']]]);

    $calc = app(ExpectedReturnDateService::class)->compute(p3Request());

    expect($calc['expected_return_date'])->toBe('2026-10-20')
        ->and($calc['snapshot']['public_holidays_considered'])->toContain('2026-10-19');
});

it('opens the case idempotently and only for an approved request', function () {
    $first = p3Open();
    $second = p3Open();

    expect($second->id)->toBe($first->id)
        ->and(LeaveReturn::where('leave_request_id', p3Request()->id)->count())->toBe(1);

    // A pending (not yet approved) request yields no case.
    $submitted = LeaveRequest::where('ref', 'LVE-2026-0162')->firstOrFail(); // r8, submitted
    expect(app(ReturnToWorkService::class)->openForRequest($submitted))->toBeNull();
});

it('records an on-time return without any deduction', function () {
    $case = p3Open();

    p3Report(p3Request()->id, 'u7', '2026-10-19')
        ->assertOk()
        ->assertJsonPath('data.status', LeaveReturn::STATUS_REPORTED_ON_TIME)
        ->assertJsonPath('data.late_days', 0);

    expect(BalanceTransaction::count())->toBe(0);
    expect($case->fresh()->deducted_days)->toBe(0);
});

it('marks a late return pending review and requires an explanation', function () {
    p3Open();

    // Late without explanation is refused.
    p3Report(p3Request()->id, 'u7', '2026-10-22')
        ->assertStatus(422)
        ->assertJsonValidationErrors('explanation');

    // With an explanation it is accepted and parked for review.
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Flooding on the return route.')
        ->assertOk()
        ->assertJsonPath('data.status', LeaveReturn::STATUS_LATE_PENDING_REVIEW)
        ->assertJsonPath('data.late_days', 3);

    // No balance effect happens before a reviewer decides.
    expect(BalanceTransaction::count())->toBe(0);
});

it('lets only the owner report their own return', function () {
    p3Open();

    // u8 holds report_own but does not own r1.
    p3Report(p3Request()->id, 'u8', '2026-10-19')->assertStatus(403);
});

it('allows an explanation to be supplied for a late return awaiting review', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Initial note.');
    $case = LeaveReturn::firstOrFail();

    $this->postJson(
        "/api/leave-returns/{$case->id}/explanation",
        ['explanation' => 'Medical appointment ran long.'],
        p3Headers(apiLogin('u7')),
    )->assertOk()->assertJsonPath('data.explanation', 'Medical appointment ran long.');
});

it('scopes review to the reviewer department or zone', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.');
    $case = LeaveReturn::firstOrFail();

    // u4 is Zonal Head for z2 only; e7 is in z1.
    p3Review($case->id, 'u4', ['excused_days' => 3])->assertStatus(403);

    // u6 heads department d2, where e7 works.
    p3Review($case->id, 'u6', ['excused_days' => 3, 'comment' => 'Accepted.'])
        ->assertOk()
        ->assertJsonPath('data.status', LeaveReturn::STATUS_EXCUSED);
});

it('validates excused days against the late days', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.'); // 3 late days
    $case = LeaveReturn::firstOrFail();

    p3Review($case->id, 'u6', ['excused_days' => 4])
        ->assertStatus(422)
        ->assertJsonValidationErrors('excused_days');

    p3Review($case->id, 'u6', ['excused_days' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('excused_days');
});

it('derives unauthorised and deducted days server-side, ignoring client overrides', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.'); // 3 late days
    $case = LeaveReturn::firstOrFail();

    $response = p3Review($case->id, 'u6', [
        'excused_days' => 1,
        'apply_deduction' => true,
        'unauthorised_days' => 0,   // client lie, must be ignored
        'deducted_days' => 0,       // client lie, must be ignored
    ])->assertOk();

    expect($response->json('data.unauthorised_days'))->toBe(2)
        ->and($response->json('data.deducted_days'))->toBe(2)
        ->and($response->json('data.status'))->toBe(LeaveReturn::STATUS_PARTIALLY_EXCUSED);
});

it('posts exactly one idempotent deduction and never touches leave_balances', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.'); // 3 late days
    $case = LeaveReturn::firstOrFail();

    $before = app(PolicyService::class)->balanceFor('e7', 'lt1');

    p3Review($case->id, 'u6', ['excused_days' => 0, 'apply_deduction' => true, 'comment' => 'Deduct 3.'])
        ->assertOk()
        ->assertJsonPath('balance_transaction.direction', 'debit');

    expect(BalanceTransaction::count())->toBe(1);

    $txn = BalanceTransaction::firstOrFail();
    expect($txn->transaction_type)->toBe('late_return_deduction')
        ->and($txn->direction)->toBe('debit')
        ->and((float) $txn->amount)->toBe(3.0)
        ->and($txn->source_type)->toBe('LeaveReturn')
        ->and((int) $txn->source_id)->toBe($case->id)
        ->and($txn->idempotency_key)->toBe("return:{$case->id}:late-deduction");

    // The ledger moved; the dynamic usage of the approved request did not change.
    $after = app(PolicyService::class)->balanceFor('e7', 'lt1');
    expect($after['used'])->toBe($before['used'])
        ->and($after['ledgerDebits'])->toBe($before['ledgerDebits'] + 3.0)
        ->and($after['remaining'])->toBe(max(0.0, $before['remaining'] - 3.0));
});

it('is retry-safe: a retried report and a retried review never duplicate', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.');
    $case = LeaveReturn::firstOrFail();

    // Same report again is a no-op rather than a 422.
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.')->assertOk();
    expect(LeaveReturn::count())->toBe(1);

    p3Review($case->id, 'u6', ['excused_days' => 0, 'apply_deduction' => true])->assertOk();
    expect(BalanceTransaction::count())->toBe(1);

    // A second review is refused by the state guard, and no second debit lands.
    p3Review($case->id, 'u6', ['excused_days' => 0, 'apply_deduction' => true])->assertStatus(422);
    expect(BalanceTransaction::count())->toBe(1);
});

it('rolls back the review and the ledger write together', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.');
    $case = LeaveReturn::firstOrFail();

    $mock = Mockery::mock(BalanceTransactionService::class)->makePartial();
    $mock->shouldReceive('record')->andThrow(new RuntimeException('ledger unavailable'));
    app()->instance(BalanceTransactionService::class, $mock);

    p3Review($case->id, 'u6', ['excused_days' => 0, 'apply_deduction' => true])->assertStatus(500);

    expect($case->fresh()->status)->toBe(LeaveReturn::STATUS_LATE_PENDING_REVIEW)
        ->and(BalanceTransaction::count())->toBe(0);
});

it('preserves the historical leave request through a late-return review', function () {
    $request = p3Request();
    $snapshot = [
        'status' => $request->status,
        'end_date' => $request->end_date->toDateString(),
        'days' => (int) $request->days,
    ];
    $requestCount = LeaveRequest::where('employee_id', 'e7')->count();

    p3Open();
    p3Report($request->id, 'u7', '2026-10-24', 'Late.');
    $case = LeaveReturn::firstOrFail();
    p3Review($case->id, 'u6', ['excused_days' => 0, 'apply_deduction' => true])->assertOk();

    $after = $request->fresh();
    expect([
        'status' => $after->status,
        'end_date' => $after->end_date->toDateString(),
        'days' => (int) $after->days,
    ])->toBe($snapshot)
        ->and(LeaveRequest::where('employee_id', 'e7')->count())->toBe($requestCount);
});

it('refuses review, excuse and deduction to callers without the permission', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.');
    $case = LeaveReturn::firstOrFail();

    // u8 has no leave_returns.review code at all.
    p3Review($case->id, 'u8', ['excused_days' => 0])->assertStatus(403);

    expect($case->fresh()->status)->toBe(LeaveReturn::STATUS_LATE_PENDING_REVIEW);
});

it('closes a decided case, records the closure, and is idempotent', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-19'); // on time
    $case = LeaveReturn::firstOrFail();

    $this->postJson("/api/leave-returns/{$case->id}/close", ['comment' => 'File complete.'], p3Headers(apiLogin('u6')))
        ->assertOk()
        ->assertJsonPath('data.status', LeaveReturn::STATUS_CLOSED)
        ->assertJsonPath('data.closure_reason', 'File complete.')
        ->assertJsonPath('data.closed_by', 'e6');

    $closed = $case->fresh();
    expect($closed->closed_at)->not->toBeNull()
        ->and($closed->closed_by)->toBe('e6')
        ->and($closed->closure_reason)->toBe('File complete.');

    $closureEvents = fn () => AuditLog::where('entity_type', 'leave_return')
        ->where('entity_id', (string) $case->id)
        ->where('action', 'closed')
        ->count();

    expect($closureEvents())->toBe(1);

    // Idempotent: closing again stays closed and writes no second audit event.
    $this->postJson("/api/leave-returns/{$case->id}/close", [], p3Headers(apiLogin('u6')))
        ->assertOk()
        ->assertJsonPath('data.status', LeaveReturn::STATUS_CLOSED);

    expect($closureEvents())->toBe(1)
        ->and($case->fresh()->closed_at->toDateTimeString())->toBe($closed->closed_at->toDateTimeString());
});

it('refuses to close a case that has not been decided', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.');
    $case = LeaveReturn::firstOrFail();

    expect($case->status)->toBe(LeaveReturn::STATUS_LATE_PENDING_REVIEW);

    $this->postJson("/api/leave-returns/{$case->id}/close", [], p3Headers(apiLogin('u6')))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($case->fresh()->status)->toBe(LeaveReturn::STATUS_LATE_PENDING_REVIEW);
});

it('closes a deducted case only after the deduction is posted, and is not editable after closure', function () {
    p3Open();
    p3Report(p3Request()->id, 'u7', '2026-10-22', 'Late.'); // 3 late days
    $case = LeaveReturn::firstOrFail();

    p3Review($case->id, 'u6', ['excused_days' => 0, 'apply_deduction' => true])->assertOk();
    expect(BalanceTransaction::count())->toBe(1);

    // The deduction is posted, so closure is now allowed.
    $this->postJson("/api/leave-returns/{$case->id}/close", [], p3Headers(apiLogin('u6')))
        ->assertOk()
        ->assertJsonPath('data.status', LeaveReturn::STATUS_CLOSED);

    // A closed case is sealed: report, explanation and review all refuse it.
    p3Report(p3Request()->id, 'u7', '2026-10-25', 'Changed my mind.')->assertStatus(422);
    $this->postJson("/api/leave-returns/{$case->id}/explanation", ['explanation' => 'x'], p3Headers(apiLogin('u7')))
        ->assertStatus(422);
    p3Review($case->id, 'u6', ['excused_days' => 3])->assertStatus(422);

    expect($case->fresh()->status)->toBe(LeaveReturn::STATUS_CLOSED);
});
