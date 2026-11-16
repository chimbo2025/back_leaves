<?php

use App\Models\Employee;

/**
 * Avatar initials contract.
 *
 * Initials are derived from the employee's name parts, not stored verbatim, so
 * every response (list, detail, auth, request, payment) stays consistent even
 * for staff registered after the feature shipped.
 */

it('derives two-letter initials from first and last names', function (string $name, string $expected) {
    [$first, $middle, $last] = Employee::splitFullName($name);

    expect(Employee::initialsFor($first, $last, $name))->toBe($expected);
    expect(strlen(Employee::initialsFor($first, $last, $name)))->toBeLessThanOrEqual(2);
})->with([
    'Haji Ali Said' => ['Haji Ali Said', 'HS'],
    'Asha Juma Hamisi' => ['Asha Juma Hamisi', 'AH'],
    'Ali Hassan Juma' => ['Ali Hassan Juma', 'AJ'],
    'Fatma Omar' => ['Fatma Omar', 'FO'],
    'extra spaces' => ['  Haji   Ali    Said  ', 'HS'],
    'lowercase' => ['haji said', 'HS'],
    'hyphenated last name' => ['Amina-Hawa Nuhu', 'AN'],
    'apostrophe in name' => ["Nuhu's Said", 'NS'],
]);

it('falls back to two letters when one name part is missing', function (?string $first, ?string $last, string $full, string $expected) {
    expect(Employee::initialsFor($first, $last, $full))->toBe($expected);
})->with([
    'first name only' => ['Haji', null, 'Haji', 'HA'],
    'last name only' => [null, 'Said', 'Said', 'SA'],
    'padded first name only' => ['  haji  ', null, '  haji  ', 'HA'],
    'padded last name only' => [null, ' said ', ' said ', 'SA'],
    'single token' => ['Asha', '', '', 'AS'],
]);

it('returns a visible placeholder when no name exists at all', function () {
    expect(Employee::initialsFor(null, null, null))->toBe('--');
    expect(Employee::initialsFor('', '   ', '   '))->toBe('--');
});

it('keeps name parts, full name and initials in sync on save', function () {
    $employee = new Employee([
        'id' => 'e999',
        'full_name' => '  Haji   Ali Said ',
        'position_id' => null,
        'department_id' => 'd1',
        'zone_id' => 'z1',
        'hire_date' => '2026-01-01',
    ]);

    // The saving hook is what normalises these; invoke it directly so the test
    // needs no database row.
    $employee->save();

    expect($employee->first_name)->toBe('Haji')
        ->and($employee->middle_name)->toBe('Ali')
        ->and($employee->last_name)->toBe('Said')
        ->and($employee->initials)->toBe('HS')
        ->and($employee->avatar_initials)->toBe('HS');

    $employee->forceFill([
        'id' => 'e999',
        'full_name' => 'Asha Juma Hamisi',
        'first_name' => null,
        'middle_name' => null,
        'last_name' => null,
        'initials' => null,
    ])->save();

    expect($employee->avatar_initials)->toBe('AH')
        ->and($employee->initials)->toBe('AH');
});

it('never overwrites an explicitly supplied initials value', function () {
    $employee = new Employee([
        'id' => 'e998',
        'full_name' => 'Haji Ali Said',
        'initials' => 'AY',
        'department_id' => 'd1',
        'zone_id' => 'z1',
        'hire_date' => '2026-01-01',
    ]);

    $employee->save();

    expect($employee->initials)->toBe('AY')
        // The avatar is still derived from the name, not the legacy column.
        ->and($employee->avatar_initials)->toBe('HS');
});

it('includes avatar initials in the signed-in user payload', function () {
    // u7 is Asha Hassan Haji (e7).
    $token = apiLogin('u7');

    $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('user.employee.avatar_initials', 'AH')
        ->assertJsonPath('user.employee.initials', 'AH')
        ->assertJsonPath('user.employee.first_name', 'Asha')
        ->assertJsonPath('user.employee.last_name', 'Haji');
});

it('includes avatar initials for every row of the staff list', function () {
    $token = apiLogin('u1');

    $rows = $this->getJson('/api/org/employees', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json('data');

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row)->toHaveKey('avatar_initials')
            ->and($row['avatar_initials'])->not->toBe('')
            ->and(mb_strlen($row['avatar_initials']))->toBeLessThanOrEqual(2);
    }

    $byId = collect($rows)->keyBy('id');
    expect($byId['e1']['avatar_initials'])->toBe('AK') // Ali Mwinyi Khamis
        ->and($byId['e7']['avatar_initials'])->toBe('AH') // Asha Hassan Haji
        ->and($byId['e12']['avatar_initials'])->toBe('HA'); // Haji Mwinyi Abdullah
});

it('returns the computed avatar immediately after registering an employee', function () {
    $admin = apiLogin('u1');

    $created = $this->postJson('/api/org/employees', [
        'full_name' => 'Haji Ali Said',
        'position_id' => 'p8',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'hire_date' => '2026-11-01',
        'username' => 'haji.said',
        'password' => 'Password123',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    expect($created['avatar_initials'])->toBe('HS')
        ->and($created['initials'])->toBe('HS')
        ->and($created['first_name'])->toBe('Haji')
        ->and($created['middle_name'])->toBe('Ali')
        ->and($created['last_name'])->toBe('Said')
        ->and($created['avatar_url'])->toBeNull();

    // The persisted row agrees with the response, so the next list load (the
    // one the UI performs after registration) shows the same initials.
    $stored = Employee::findOrFail($created['id']);
    expect($stored->avatar_initials)->toBe('HS')
        ->and($stored->initials)->toBe('HS');

    $list = collect($this->getJson('/api/org/employees', ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->json('data'))->keyBy('id');

    expect($list[$created['id']]['avatar_initials'])->toBe('HS');
});

it('accepts explicit name parts and derives the rest', function () {
    $admin = apiLogin('u1');

    $created = $this->postJson('/api/org/employees', [
        'full_name' => 'Asha Juma Hamisi',
        'first_name' => 'Asha',
        'last_name' => 'Hamisi',
        'department_id' => 'd2',
        'zone_id' => 'z1',
        'hire_date' => '2026-11-01',
        'username' => 'asha.hamisi',
        'password' => 'Password123',
    ], ['Authorization' => "Bearer {$admin}"])->assertStatus(201)->json('data');

    expect($created['avatar_initials'])->toBe('AH')
        ->and($created['middle_name'])->toBe('Juma');
});

it('recomputes initials when the display name changes', function () {
    $admin = apiLogin('u1');

    // e12 is Haji Mwinyi Abdullah (HA). Renaming through the profile patch
    // must move the avatar with it, not leave the old letters behind.
    $this->patchJson('/api/org/employees/e12', [
        'full_name' => 'Fatma Omar',
    ], ['Authorization' => "Bearer {$admin}"])->assertOk();

    $employee = Employee::findOrFail('e12');

    expect($employee->first_name)->toBe('Fatma')
        ->and($employee->last_name)->toBe('Omar')
        ->and($employee->avatar_initials)->toBe('FO')
        ->and($employee->initials)->toBe('FO');
});

it('exposes the requester avatar on leave request responses', function () {
    $token = apiLogin('u3');

    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(40)->toDateString(),
        'end_date' => now()->addDays(44)->toDateString(),
        'reason' => 'Annual leave for the avatar contract.',
    ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

    $detail = $this->getJson('/api/requests/'.$created->json('data.id'), ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json();

    // e3 is Fatma Abdalla Hassan.
    expect($detail['employee']['full_name'])->toBe('Fatma Abdalla Hassan')
        ->and($detail['employee']['avatar_initials'])->toBe('FH')
        ->and($detail['employee']['first_name'])->toBe('Fatma')
        ->and($detail['employee']['last_name'])->toBe('Hassan');
});
