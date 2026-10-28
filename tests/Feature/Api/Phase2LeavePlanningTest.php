<?php

use App\Models\LeavePlan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveWaiver;

/**
 * Phase 2 — leave waivers/deferrals and almanac leave planning (spec PART F).
 */
function createPlanViaApi(string $user, array $overrides = []): array
{
    $token = apiLogin($user);
    $r = test()->postJson('/api/leave-plans', array_merge(['leave_type_id' => 'lt1'], $overrides), ['Authorization' => "Bearer {$token}"]);
    $r->assertStatus(201);

    return [$r->json('data.id'), $token];
}

function proposeViaApi(string $token, string $planId, array $payload = []): array
{
    $r = test()->postJson("/api/leave-plans/{$planId}/propose", $payload, ['Authorization' => "Bearer {$token}"]);
    $r->assertOk();

    return $r->json();
}

function authHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

beforeEach(function () {
    // LeaveDateProposalService reads these at proposal time; reset so tests in
    // this file cannot leak config from one scenario into the next.
    config([
        'leave.public_holidays' => [],
        'leave.blackout_periods' => [],
        'leave.staffing_minimums' => [],
    ]);
});

it('reuses the existing leave types and refuses invented ones', function () {
    expect(LeaveType::count())->toBe(7);

    $token = apiLogin('u7');
    $this->postJson('/api/leave-plans', ['leave_type_id' => 'lt-DOES-NOT-EXIST'], authHeaders($token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('leave_type_id');
});

it('lets an employee create and submit their own waiver', function () {
    $token = apiLogin('u7');

    $created = $this->postJson('/api/leave-waivers', [
        'leave_type_id' => 'lt1',
        'requested_action' => 'employee_declined',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-10',
        'reason' => 'Shifting annual leave to a quieter month.',
    ], authHeaders($token))->assertStatus(201);

    $id = $created->json('data.id');
    expect(LeaveWaiver::find($id)->status)->toBe('draft');

    $this->postJson("/api/leave-waivers/{$id}/submit", [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.requested_by', 'e7');
});

it('rejects an unknown requested_action', function () {
    $token = apiLogin('u7');
    $this->postJson('/api/leave-waivers', ['leave_type_id' => 'lt1', 'requested_action' => 'burn_balance'], authHeaders($token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('requested_action');
});

it('prevents an employee from approving their own waiver', function () {
    $token = apiLogin('u7');
    $id = $this->postJson('/api/leave-waivers', [
        'leave_type_id' => 'lt1',
        'requested_action' => 'employee_requested_deferral',
    ], authHeaders($token))->json('data.id');
    $this->postJson("/api/leave-waivers/{$id}/submit", [], authHeaders($token))->assertOk();

    // No approve permission and the policy's owner-exclusion both refuse this.
    $this->postJson("/api/leave-waivers/{$id}/approve", [], authHeaders($token))
        ->assertStatus(403);

    expect(LeaveWaiver::find($id)->status)->toBe('submitted');
});

it('lets a reviewer review and approve a waiver and records an auditable effect', function () {
    $token = apiLogin('u7');
    $id = $this->postJson('/api/leave-waivers', [
        'leave_type_id' => 'lt1',
        'requested_action' => 'employee_requested_deferral',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-10',
        'reason' => 'Deferring two weeks.',
    ], authHeaders($token))->json('data.id');
    $this->postJson("/api/leave-waivers/{$id}/submit", [], authHeaders($token))->assertOk();

    $hr = apiLogin('u3');
    $this->postJson("/api/leave-waivers/{$id}/review", ['comment' => 'Under review.'], authHeaders($hr))
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');

    $decided = $this->postJson("/api/leave-waivers/{$id}/approve", ['comment' => 'Approved.'], authHeaders($hr))
        ->assertOk();

    $waiver = LeaveWaiver::find($id);
    expect($waiver->status)->toBe('approved');
    expect($waiver->reviewed_by)->toBe('e3');
    expect($waiver->reviewed_at)->not->toBeNull();
    expect($decided->json('effect.type'))->toBe('deferral_credit');
    expect($waiver->effect_json['ledger'])->toBe('balance_transactions');
    expect($waiver->policy_snapshot)->not->toBeEmpty();

    // The effect is applied as exactly one append-only ledger credit.
    expect($decided->json('balance_transaction.direction'))->toBe('credit')
        ->and($decided->json('balance_transaction.transaction_type'))->toBe('carry_forward')
        ->and((float) $decided->json('balance_transaction.amount'))->toBe(10.0);
});

it('expires a waiver whose cycle has already passed', function () {
    $token = apiLogin('u7');
    $id = $this->postJson('/api/leave-waivers', [
        'leave_type_id' => 'lt1',
        'requested_action' => 'rescheduled',
        'leave_cycle_start' => now()->subDays(10)->toDateString(),
        'leave_cycle_end' => now()->subDay()->toDateString(),
    ], authHeaders($token))->json('data.id');

    $this->postJson("/api/leave-waivers/{$id}/submit", [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'expired');
});

it('generates exactly three ranked options when the cycle allows it', function () {
    // e7's maternity entitlement is statutory (84 days) with no competing
    // requests of that type, so a full-year proposal finds plenty of windows.
    [$planId, $token] = createPlanViaApi('u7', ['leave_type_id' => 'lt3']);
    $proposal = proposeViaApi($token, $planId);

    $options = $proposal['options'];
    expect($options)->toHaveCount(3);
    expect(array_column($options, 'score_breakdown_json'))->each->not->toBeNull();
    expect($options[0]['score'])->toBeGreaterThanOrEqual($options[1]['score']);
    expect($options[1]['score'])->toBeGreaterThanOrEqual($options[2]['score']);
    expect(LeavePlan::find($planId)->status)->toBe('proposed');
});

it('explains why fewer than three options were generated', function () {
    // A cycle short enough that even the minimum-notice window cannot fit.
    [$planId, $token] = createPlanViaApi('u7', [
        'cycle_start' => now()->addDays(2)->toDateString(),
        'cycle_end' => now()->addDays(6)->toDateString(),
    ]);

    $proposal = proposeViaApi($token, $planId);

    expect($proposal['options'])->toBeEmpty();
    expect($proposal['explanation'])->not->toBeNull()->toContain('No valid date options');
});

it('excludes blackout dates from proposals', function () {
    config(['leave.blackout_periods' => [[
        'start_date' => now()->addDays(14)->toDateString(),
        'end_date' => now()->addDays(21)->toDateString(),
        'label' => 'Budget freeze',
    ]]]);

    [$planId, $token] = createPlanViaApi('u7');
    $options = proposeViaApi($token, $planId, ['desired_days' => 1])['options'];

    expect($options)->toHaveCount(3);
    foreach ($options as $option) {
        expect($option['start_date'])->toBeGreaterThan(now()->addDays(21)->toDateString());
    }
});

it('respects staffing minimums', function () {
    // d2 has 4 heads; require 6 present so no window can satisfy the rule.
    config(['leave.staffing_minimums' => [
        ['department_id' => 'd2', 'minimum_present' => 6],
    ]]);

    [$planId, $token] = createPlanViaApi('u7');
    $proposal = proposeViaApi($token, $planId, ['desired_days' => 1]);

    expect($proposal['options'])->toBeEmpty();
    expect($proposal['explanation'])->toContain('Staffing');
});

it('respects balance and notice rules', function () {
    // Balance: e7 has 12 accrued days of ANNUAL and has used 10, so a 30-day
    // window can never be proposed.
    [$planId, $token] = createPlanViaApi('u7');
    $balance = proposeViaApi($token, $planId, ['desired_days' => 30]);
    expect($balance['options'])->toBeEmpty();
    expect($balance['explanation'])->toContain('balance');

    // Notice: a cycle ending inside the 14-day notice window yields nothing.
    [$noticePlan, $noticeToken] = createPlanViaApi('u7', [
        'cycle_start' => now()->addDays(2)->toDateString(),
        'cycle_end' => now()->addDays(6)->toDateString(),
    ]);
    $notice = proposeViaApi($noticeToken, $noticePlan);
    expect($notice['options'])->toBeEmpty();
    expect($notice['explanation'])->not->toBeNull();
});

it('does not let an expired plan be selected', function () {
    [$planId, $token] = createPlanViaApi('u7', [
        'cycle_start' => now()->subDays(5)->toDateString(),
        'cycle_end' => now()->subDay()->toDateString(),
    ]);

    // Proposing on an already-passed cycle produces no options, but the select
    // transition must refuse even if an option is forced into the record.
    proposeViaApi($token, $planId);
    LeavePlan::find($planId)->options()->create([
        'option_number' => 1,
        'start_date' => now()->subDays(3)->toDateString(),
        'end_date' => now()->subDays(2)->toDateString(),
        'days' => 2,
    ]);

    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => 1], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'expired');

    $plan = LeavePlan::find($planId);
    expect($plan->selected_option)->toBeNull();
});

it('revalidates a selected plan when circumstances change', function () {
    [$planId, $token] = createPlanViaApi('u7');
    $option = proposeViaApi($token, $planId, ['desired_days' => 1])['options'][0];

    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => $option['option_number']], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'employee_selected');

    // Sabotage: an approved request now occupies the chosen window.
    $window = LeavePlan::find($planId)->options()->where('option_number', $option['option_number'])->first();

    [$secondId, $secondToken] = createPlanViaApi('u7');
    $secondOption = proposeViaApi($secondToken, $secondId, ['desired_days' => 1])['options'][0];
    LeaveRequest::create([
        'ref' => 'LVE-PH2-OVL',
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'policy_id' => 'pol1',
        'start_date' => $secondOption['start_date'],
        'end_date' => $secondOption['end_date'],
        'days' => 1,
        'status' => 'approved',
    ]);

    $this->postJson("/api/leave-plans/{$secondId}/select", ['option_number' => $secondOption['option_number']], authHeaders($secondToken))
        ->assertStatus(422);
});

it('converts a plan into one request, preserving the policy snapshot', function () {
    [$planId, $token] = createPlanViaApi('u7');
    $option = proposeViaApi($token, $planId, ['desired_days' => 1])['options'][0];
    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => $option['option_number']], authHeaders($token))->assertOk();

    $hr = apiLogin('u3');
    $this->postJson("/api/leave-plans/{$planId}/confirm", ['decision' => 'confirmed', 'comment' => 'Confirmed.'], authHeaders($hr))
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.confirmed_by', 'e3');

    $converted = $this->postJson("/api/leave-plans/{$planId}/convert", [], authHeaders($token))
        ->assertStatus(201)
        ->json();

    $requestId = $converted['request']['id'];
    $draft = $converted['request'];

    expect($draft['policy_id'])->toBe('pol1');
    expect($draft['policy_version'])->toBe('4');
    expect($draft['policy_snapshot'])->not->toBeEmpty();

    $plan = LeavePlan::find($planId);
    expect($plan->status)->toBe('converted_to_request');
    expect($plan->leave_request_id)->toBe($requestId);

    $views = $this->getJson('/api/leave-plans/'.$planId, authHeaders($token))
        ->assertOk()
        ->json('data');

    expect($views['confirmed_start_date'])->toBe($views['confirmed_end_date']);
});

it('makes repeated conversion idempotent and never duplicates the request', function () {
    [$planId, $token] = createPlanViaApi('u7');
    $option = proposeViaApi($token, $planId, ['desired_days' => 1])['options'][0];
    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => $option['option_number']], authHeaders($token))->assertOk();

    $first = $this->postJson("/api/leave-plans/{$planId}/convert", [], authHeaders($token))->assertStatus(201)->json('request');
    $second = $this->postJson("/api/leave-plans/{$planId}/convert", [], authHeaders($token))->assertOk()->json('request');

    expect($second['id'])->toBe($first['id']);

    $forWindow = LeaveRequest::where('employee_id', 'e7')
        ->where('leave_type_id', 'lt1')
        ->where('start_date', $first['start_date'])
        ->where('end_date', $first['end_date'])
        ->count();
    expect($forWindow)->toBe(1);
});

it('leaves data unchanged when conversion is re-blocked', function () {
    [$planId, $token] = createPlanViaApi('u7');
    $option = proposeViaApi($token, $planId, ['desired_days' => 1])['options'][0];
    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => $option['option_number']], authHeaders($token))->assertOk();

    LeaveRequest::create([
        'ref' => 'LVE-PH2-OVL2',
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'policy_id' => 'pol1',
        'start_date' => $option['start_date'],
        'end_date' => $option['end_date'],
        'days' => 1,
        'status' => 'approved',
    ]);

    $before = LeaveRequest::where('employee_id', 'e7')->count();

    $this->postJson("/api/leave-plans/{$planId}/convert", [], authHeaders($token))
        ->assertStatus(422);

    expect(LeaveRequest::where('employee_id', 'e7')->count())->toBe($before);
    expect(LeavePlan::find($planId)->status)->toBe('employee_selected');
    expect(LeavePlan::find($planId)->leave_request_id)->toBeNull();
});

it('keeps managers inside their own zone and department scope', function () {
    [$planZ2, $tokenZ2] = createPlanViaApi('u8'); // e8: d4, z2
    [$planZ1, $tokenZ1] = createPlanViaApi('u10'); // e10: d6, z1

    // Zonal head of z2 (and head of d4) sees only the z2 plan.
    $zh = apiLogin('u4');
    $list = $this->getJson('/api/leave-plans?scope=all&per_page=50', authHeaders($zh))->assertOk()->json('data.data');
    $ids = collect($list)->pluck('id');

    expect($ids)->toContain($planZ2)->not->toContain($planZ1);

    // HR's explicit org-wide code sees both.
    $hr = apiLogin('u3');
    $all = $this->getJson('/api/leave-plans?scope=all&per_page=50', authHeaders($hr))->assertOk()->json('data.data');
    expect(collect($all)->pluck('id'))->toContain($planZ2, $planZ1);
});

it('keeps sensitive data out of the manager calendar', function () {
    [$planId, $token] = createPlanViaApi('u7');
    $option = proposeViaApi($token, $planId, ['desired_days' => 1])['options'][0];
    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => $option['option_number']], authHeaders($token))->assertOk();

    $hr = apiLogin('u3');
    $this->postJson("/api/leave-plans/{$planId}/confirm", ['decision' => 'confirmed'], authHeaders($hr))->assertOk();

    $calendar = $this->getJson('/api/leave-plans/calendar', authHeaders($hr))->assertOk()->json('data');
    expect($calendar)->not->toBeEmpty();

    foreach ($calendar as $entry) {
        expect($entry)->toHaveKeys(['employee_id', 'employee_name', 'department_id', 'zone_id', 'leave_type_id', 'status', 'start_date', 'end_date']);
        expect($entry)->not->toHaveKey('reason')
            ->not->toHaveKey('score_breakdown_json')
            ->not->toHaveKey('medical')
            ->not->toHaveKey('notes');
    }
});

it('denies unauthorised access to plans and waivers', function () {
    $token = apiLogin('u7');

    // Reading another employee's plan is refused.
    [$otherPlan, $otherToken] = createPlanViaApi('u8');
    $this->getJson('/api/leave-plans/'.$otherPlan, authHeaders($token))->assertStatus(403);

    // Confirming another employee's plan is refused.
    $this->postJson("/api/leave-plans/{$otherPlan}/confirm", ['decision' => 'confirmed'], authHeaders($token))->assertStatus(403);

    // A self-service user has no manager window.
    $this->getJson('/api/leave-plans?scope=all', authHeaders($token))->assertStatus(403);

    // Reviewing someone else's waiver is refused.
    $waiver = $this->postJson('/api/leave-waivers', ['leave_type_id' => 'lt1', 'requested_action' => 'employee_declined'], authHeaders(apiLogin('u8')))->json('data.id');
    $this->postJson("/api/leave-waivers/{$waiver}/review", [], authHeaders($token))->assertStatus(403);
    $this->postJson("/api/leave-waivers/{$waiver}/approve", [], authHeaders($token))->assertStatus(403);
});
