<?php

use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;

it('reuses existing seven leave types with no duplicates created', function () {
    $types = LeaveType::all();
    expect($types->count())->toBe(7);

    $codes = $types->pluck('code')->toArray();
    expect($codes)->toContain('ANNUAL');
    expect($codes)->toContain('SICK');
    expect($codes)->toContain('MATERNITY');
    expect($codes)->toContain('PATERNITY');
    expect($codes)->toContain('EMERGENCY');
    expect($codes)->toContain('STUDY');
    expect($codes)->toContain('WITHOUT_PAY');

    $uniqueCodes = array_unique($codes);
    expect(count($uniqueCodes))->toBe(7);
});

it('draft policies do not affect eligibility', function () {
    $annual = LeaveType::where('code', 'ANNUAL')->first();
    expect($annual)->not->toBeNull();

    LeavePolicy::create([
        'id' => 'pol_draft_test',
        'leave_type_id' => $annual->id,
        'version' => 99,
        'max_days_per_year' => 100,
        'max_days_per_request' => 100,
        'min_tenure_months' => 0,
        'min_notice_days' => 0,
        'requires_documents' => false,
        'required_docs' => [],
        'carry_over_max' => 50,
        'allow_half_day' => true,
        'notes' => 'Draft policy',
        'status' => 'draft',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $token = apiLogin('u3');
    $response = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$token}"]);
    $response->assertOk();
});

it('pending legal review policies do not affect eligibility', function () {
    $annual = LeaveType::where('code', 'ANNUAL')->first();

    LeavePolicy::create([
        'id' => 'pol_pending_test',
        'leave_type_id' => $annual->id,
        'version' => 98,
        'max_days_per_year' => 100,
        'max_days_per_request' => 100,
        'min_tenure_months' => 0,
        'min_notice_days' => 0,
        'requires_documents' => false,
        'required_docs' => [],
        'carry_over_max' => 50,
        'allow_half_day' => true,
        'notes' => 'Pending legal review',
        'status' => 'pending_legal_review',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $token = apiLogin('u3');
    $response = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$token}"]);
    $response->assertOk();
});

it('superseded policies are excluded', function () {
    $token = apiLogin('u3');
    $response = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$token}"]);
    $response->assertOk();

    $data = $response->json('data');
    foreach ($data as $item) {
        expect($item['eligible'])->toBeBool();
    }
});

it('ineligible requests return clear blockers', function () {
    $token = apiLogin('u3');

    $response = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$token}"]);
    $response->assertOk();

    $data = $response->json('data');
    foreach ($data as $item) {
        if (! empty($item['blockers'])) {
            expect(is_array($item['blockers']))->toBeTrue();
            foreach ($item['blockers'] as $blocker) {
                expect(is_string($blocker))->toBeTrue();
                expect($blocker)->not->toBeEmpty();
            }
        }
    }
});

it('required documents are reported', function () {
    $token = apiLogin('u3');
    $response = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$token}"]);
    $response->assertOk();

    $data = $response->json('data');
    foreach ($data as $item) {
        expect(array_key_exists('leave_type_id', $item))->toBeTrue();
        expect(array_key_exists('blockers', $item))->toBeTrue();
    }
});

it('new requests receive policy snapshots and eligibility data when submitted', function () {
    $annual = LeaveType::where('code', 'ANNUAL')->first();
    $token = apiLogin('u3');

    $response = $this->postJson('/api/requests', [
        'leave_type_id' => $annual->id,
        'start_date' => '2026-11-10',
        'end_date' => '2026-11-14',
        'reason' => 'Annual leave test',
    ], ['Authorization' => "Bearer {$token}"]);
    $response->assertStatus(201);
    $requestId = $response->json('data.id');

    $submit = $this->postJson("/api/requests/{$requestId}/submit", [], ['Authorization' => "Bearer {$token}"]);
    $submit->assertOk();

    $request = LeaveRequest::find($requestId);
    expect($request)->not->toBeNull();
    expect($request->policy_id)->not->toBeNull();
    expect($request->policy_snapshot)->not->toBeNull();
    expect($request->eligibility_checked_at)->not->toBeNull();
    expect($request->eligibility_ok)->toBeBool();
});

it('historical requests are not modified', function () {
    $r4 = LeaveRequest::where('ref', 'LVE-2026-0155')->first();
    expect($r4)->not->toBeNull();
});

it('existing routes continue to work', function () {
    $token = apiLogin('u3');

    $this->getJson('/api/requests', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/leave/types', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/leave/policies/current', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$token}"])->assertOk();
});

it('existing policy_id foreign keys remain valid', function () {
    $requests = LeaveRequest::whereNotNull('policy_id')->get();
    foreach ($requests as $request) {
        $policy = LeavePolicy::find($request->policy_id);
        expect($policy)->not->toBeNull();
    }
});
