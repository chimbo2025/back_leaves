<?php

use App\Models\BalanceTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveWaiver;
use App\Services\BalanceTransactionService;
use App\Services\LeaveWaiverService;
use App\Services\PolicyService;
use Illuminate\Testing\TestResponse;

/**
 * Phase 2 balance decision — the append-only balance_transactions ledger.
 *
 * The dynamic balanceFor() already represents accrual and usage, so the ledger
 * records ONLY approved waiver/deferral effects that change entitlement, and it
 * does so exactly once. These tests pin that contract: one transaction per
 * effect, no double counting, immutability, and corrections by reversal.
 */
function ledgerHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

function ledgerSubmitWaiver(string $ownerUser, array $payload): int
{
    $token = apiLogin($ownerUser);
    $id = test()->postJson('/api/leave-waivers', $payload, ledgerHeaders($token))->assertStatus(201)->json('data.id');
    test()->postJson("/api/leave-waivers/{$id}/submit", [], ledgerHeaders($token))->assertOk();

    return (int) $id;
}

function ledgerApprove(string $reviewerUser, int $waiverId): TestResponse
{
    return test()->postJson("/api/leave-waivers/{$waiverId}/approve", [], ledgerHeaders(apiLogin($reviewerUser)));
}

beforeEach(function () {
    config([
        'leave.public_holidays' => [],
        'leave.blackout_periods' => [],
        'leave.staffing_minimums' => [],
    ]);
});

it('posts exactly one ledger credit for an approved deferral', function () {
    $balance = app(PolicyService::class)->balanceFor('e7', 'lt3');
    expect($balance['remaining'])->toBe(84.0);

    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_requested_deferral',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-05',
        'reason' => 'Deferring five statutory days.',
    ]);

    ledgerApprove('u3', $id)->assertOk()->assertJsonPath('balance_transaction.direction', 'credit');

    expect(BalanceTransaction::count())->toBe(1);

    $txn = BalanceTransaction::first();
    expect($txn->transaction_type)->toBe('carry_forward')
        ->and($txn->direction)->toBe('credit')
        ->and((float) $txn->amount)->toBe(5.0)
        ->and($txn->source_type)->toBe('leave_waiver')
        ->and((int) $txn->source_id)->toBe($id)
        ->and($txn->leave_waiver_id)->toBe($id);

    // The dynamic entitlement and usage are untouched; only the ledger moved.
    $after = app(PolicyService::class)->balanceFor('e7', 'lt3');
    expect($after['used'])->toBe(0.0)
        ->and($after['accrued'])->toBe(84.0)
        ->and($after['ledgerCredits'])->toBe(5.0)
        ->and($after['ledgerDebits'])->toBe(0.0)
        ->and($after['remaining'])->toBe(89.0);
});

it('posts no ledger transaction when the action changes no entitlement', function () {
    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'rescheduled',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-05',
    ]);

    $response = ledgerApprove('u3', $id)->assertOk();

    expect($response->json('balance_transaction'))->toBeNull()
        ->and($response->json('effect.type'))->toBe('rescheduled')
        ->and(BalanceTransaction::count())->toBe(0);

    expect(app(PolicyService::class)->balanceFor('e7', 'lt3')['remaining'])->toBe(84.0);
});

it('does not post a second transaction when an approval is retried', function () {
    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_declined',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-07',
    ]);

    ledgerApprove('u3', $id)->assertOk();
    expect(BalanceTransaction::count())->toBe(1);

    // A second HTTP approval is refused by the state guard...
    ledgerApprove('u3', $id)->assertStatus(422);
    expect(BalanceTransaction::count())->toBe(1);

    // ...and re-posting the effect directly is idempotent rather than additive.
    $waiver = LeaveWaiver::findOrFail($id);
    $again = app(LeaveWaiverService::class)->applyBalanceEffect($waiver);

    expect($again->id)->toBe(BalanceTransaction::first()->id)
        ->and(BalanceTransaction::count())->toBe(1)
        ->and(app(PolicyService::class)->balanceFor('e7', 'lt3')['remaining'])->toBe(77.0);
});

it('applies a debit to the balance exactly once without touching dynamic usage', function () {
    $before = LeaveRequest::where('employee_id', 'e7')->count();

    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_declined',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-06',
    ]);

    ledgerApprove('u3', $id)->assertOk();

    $after = app(PolicyService::class)->balanceFor('e7', 'lt3');
    expect($after['remaining'])->toBe(78.0)
        ->and($after['used'])->toBe(0.0)
        ->and($after['ledgerDebits'])->toBe(6.0);

    // Historical leave rows are not rewritten by a waiver decision.
    expect(LeaveRequest::where('employee_id', 'e7')->count())->toBe($before);
});

it('rolls back both the waiver decision and the ledger write together', function () {
    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_requested_deferral',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-05',
    ]);

    $mock = Mockery::mock(BalanceTransactionService::class)->makePartial();
    $mock->shouldReceive('record')->andThrow(new RuntimeException('ledger unavailable'));
    app()->instance(BalanceTransactionService::class, $mock);

    ledgerApprove('u3', $id)->assertStatus(500);

    // The decision and the (failed) effect are in one transaction, so neither
    // the status change nor a partial ledger row survives.
    expect(LeaveWaiver::findOrFail($id)->status)->toBe('submitted')
        ->and(BalanceTransaction::count())->toBe(0);
});

it('refuses to create a ledger effect for an unauthorised caller', function () {
    $id = ledgerSubmitWaiver('u8', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_declined',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-05',
    ]);

    // u8 has no waiver decision permission at all.
    ledgerApprove('u8', $id)->assertStatus(403);

    expect(BalanceTransaction::count())->toBe(0)
        ->and(LeaveWaiver::findOrFail($id)->status)->toBe('submitted');
});

it('treats posted transactions as immutable', function () {
    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_declined',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-05',
    ]);
    ledgerApprove('u3', $id)->assertOk();

    $txn = BalanceTransaction::firstOrFail();

    expect(fn () => $txn->update(['amount' => 999]))
        ->toThrow(DomainException::class);

    expect(fn () => $txn->delete())
        ->toThrow(DomainException::class);

    expect(BalanceTransaction::count())->toBe(1);
});

it('corrects a posted effect with a reversal transaction, not an edit', function () {
    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employee_declined',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-09',
    ]);
    ledgerApprove('u3', $id)->assertOk();

    $original = BalanceTransaction::firstOrFail();
    expect(app(PolicyService::class)->balanceFor('e7', 'lt3')['remaining'])->toBe(75.0);

    $reversal = app(BalanceTransactionService::class)->reverse($original, 'Recorded in error.', 'u3');

    expect($reversal->transaction_type)->toBe('correction')
        ->and($reversal->direction)->toBe('credit')
        ->and($reversal->reversed_transaction_id)->toBe($original->id)
        ->and(BalanceTransaction::count())->toBe(2);

    // The ledger nets back to zero, so the entitlement is restored.
    $restored = app(PolicyService::class)->balanceFor('e7', 'lt3');
    expect($restored['remaining'])->toBe(84.0)
        ->and($restored['ledgerCredits'])->toBe(9.0)
        ->and($restored['ledgerDebits'])->toBe(9.0);
});

it('stores the policy decision snapshot on effect_json, not on a balance row', function () {
    $id = ledgerSubmitWaiver('u7', [
        'leave_type_id' => 'lt3',
        'requested_action' => 'employer_deferred',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-04',
    ]);
    ledgerApprove('u3', $id)->assertOk();

    $waiver = LeaveWaiver::findOrFail($id);

    expect($waiver->effect_json)->toHaveKey('type', 'deferral_credit')
        ->and($waiver->effect_json['ledger'])->toBe('balance_transactions')
        ->and($waiver->effect_json['days_carried'])->toBe(4)
        ->and($waiver->policy_snapshot)->not->toBeEmpty();

    // The applied record is the ledger row; effect_json is a frozen snapshot.
    $txn = BalanceTransaction::firstOrFail();
    expect($txn->metadata_json['requested_action'])->toBe('employer_deferred');
});
