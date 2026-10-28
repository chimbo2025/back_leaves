<?php

/**
 * Start-date-only requests (return date set at approval). The eligibility
 * overlap query used to build whereBetween/'>=' with a NULL end date and 500
 * on submit; these lock the null-safe interval semantics.
 */
it('submits a start-date-only request', function () {
    $token = apiLogin('u12');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'reason' => 'Open-ended annual leave; return date set at approval.',
    ], ['Authorization' => "Bearer {$token}"]);
    $created->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($created->json('data.end_date'))->toBeNull();

    $this->postJson('/api/requests/'.$created->json('data.id').'/submit', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review');
});

it('blocks a later dated request that overlaps open-ended leave', function () {
    $token = apiLogin('u12');

    $open = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'reason' => 'Open-ended block first.',
    ], ['Authorization' => "Bearer {$token}"]);
    $this->postJson('/api/requests/'.$open->json('data.id').'/submit', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    $second = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(35)->toDateString(),
        'end_date' => now()->addDays(36)->toDateString(),
        'reason' => 'Dated request inside the open-ended span.',
    ], ['Authorization' => "Bearer {$token}"]);
    $second->assertStatus(201);

    $rejected = $this->postJson('/api/requests/'.$second->json('data.id').'/submit', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422);

    expect($rejected->json('errors.eligibility'))->toContain('Overlapping leave exists.');
});

it('blocks start-date-only leave that overlaps an existing submitted request', function () {
    // e7 has submitted r9 spanning 2026-10-19..2026-10-25; a start-only
    // request beginning inside that window must be refused, not crash.
    $token = apiLogin('u7');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-10-20',
        'reason' => 'Start only, inside submitted leave.',
    ], ['Authorization' => "Bearer {$token}"]);
    $created->assertStatus(201);

    $rejected = $this->postJson('/api/requests/'.$created->json('data.id').'/submit', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422);

    expect($rejected->json('errors.eligibility'))->toContain('Overlapping leave exists.');
});

it('keeps a fully past open-ended span from blocking a later request', function () {
    // An earlier request that already ended before the new start date is
    // not an overlap, open-ended or not — the interval must not over-match.
    // Dates are relative to today: annual leave carries a 14-day minimum
    // notice, so hardcoded dates silently fall behind the window and fail
    // the notice check instead of the overlap check this test is about.
    $token = apiLogin('u12');

    $earlier = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'reason' => 'Open-ended first.',
    ], ['Authorization' => "Bearer {$token}"]);
    $this->postJson('/api/requests/'.$earlier->json('data.id').'/submit', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    // Dated request entirely BEFORE the open-ended start: no intersection.
    $before = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(20)->toDateString(),
        'end_date' => now()->addDays(24)->toDateString(),
        'reason' => 'Earlier non-overlapping window.',
    ], ['Authorization' => "Bearer {$token}"]);
    $before->assertStatus(201);

    $this->postJson('/api/requests/'.$before->json('data.id').'/submit', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk();
});
