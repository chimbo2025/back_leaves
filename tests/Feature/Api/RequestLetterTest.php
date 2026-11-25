<?php

use App\Models\Department;
use App\Models\LeaveRequest;

/**
 * The employee's application letter is a fixed Swahili template. It must be
 * persisted on the request even when the client omits the body (older client,
 * preselected leave type, or a draft saved before a type was chosen) so the
 * letter is never blank and always differs from the HR response letter.
 */
it('generates the employee application letter when the body is omitted', function () {
    $token = apiLogin('u7');

    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(60)->toDateString(),
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $fresh = LeaveRequest::findOrFail((int) $created->json('data.id'));
    $department = Department::find('d2');

    expect($fresh->request_body)
        ->toStartWith("Mimi ni mfanya kazi Afisi ya Mkurugenzi wa Mashtaka katika kitengo cha {$department->name}.")
        ->toContain('Naomba kupatiwa likizo yangu ya')
        ->toContain('kwa mujibu wa sheria za utumishi.');
});

it('keeps an explicitly provided application letter', function () {
    $token = apiLogin('u7');
    $body = 'Mimi ni mfanya kazi Afisi ya Mkurugenzi wa Mashtaka katika kitengo cha Sheria. Naomba kupatiwa likizo yangu ya Likizo ya Mwaka kwa mujibu wa sheria za utumishi.';

    $created = test()->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(60)->toDateString(),
        'request_body' => $body,
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    expect(LeaveRequest::findOrFail((int) $created->json('data.id'))->request_body)->toBe($body);
});
