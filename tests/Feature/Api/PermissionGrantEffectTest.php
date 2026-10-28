<?php

use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * A granted permission must open exactly what it implies - no more, no less.
 *
 * For every route-gated code this grants that ONE code (via a throwaway role) to
 * a plain employee, then asserts the endpoint it guards stops returning 403 while
 * its neighbours stay closed. It is the executable form of "if I give a user a
 * permission, they get exactly that permission".
 */
function grantOnly(string $code, string $employeeId = 'e8'): string
{
    [$module, $action] = explode('.', $code, 2);

    $role = Role::firstOrCreate(
        ['id' => 'PROBE_'.strtoupper(str_replace('.', '_', $code))],
        ['name' => "Probe {$code}", 'is_system' => false]
    );
    $role->permissions()->syncWithoutDetaching([
        Permission::where('module', $module)->where('action', $action)->value('id'),
    ]);
    EmployeeRole::create([
        'employee_id' => $employeeId,
        'role_id' => $role->id,
        'source' => 'manual_grant',
        'effective_from' => now()->toDateString(),
    ]);

    Cache::flush();

    return $role->id;
}

/** GET/DELETE take headers as the 2nd argument; POST/PATCH/PUT take a body. */
function probeStatus(string $token, string $method, string $uri, array $payload = []): int
{
    $headers = ['Authorization' => "Bearer {$token}"];

    return match ($method) {
        'get', 'head' => test()->{$method.'Json'}($uri, $headers)->status(),
        'delete' => test()->deleteJson($uri, [], $headers)->status(),
        default => test()->{$method.'Json'}($uri, $payload, $headers)->status(),
    };
}

/** A role that exists purely so it can be deleted inside the test. */
function throwawayRole(string $id = 'DELETE_ME'): string
{
    $role = Role::create(['id' => $id, 'name' => 'Delete Me', 'is_system' => false]);

    return $role->id;
}

it('refuses every gated endpoint for a plain employee', function () {
    $plain = apiLogin('u8');

    // policies.view / requests.view / documents.view are self-service and are
    // covered separately; everything here must be closed without a role.
    foreach ([
        ['get', '/api/roles'],
        ['post', '/api/roles'],
        ['get', '/api/audit'],
        ['get', '/api/delegations'],
        ['post', '/api/delegations'],
        ['post', '/api/workflows'],
        ['post', '/api/policies'],
        ['get', '/api/reports/zone'],
        ['get', '/api/reports/gender'],
    ] as [$method, $uri]) {
        expect(probeStatus($plain, $method, $uri))
            ->toBe(403, "{$method} {$uri} must be closed for an employee with no roles");
    }
});

it('leaves the self-service baseline open to everyone', function () {
    $plain = apiLogin('u8');

    expect(probeStatus($plain, 'get', '/api/workflows'))->toBe(200);
    expect(probeStatus($plain, 'get', '/api/requests'))->toBe(200);
});

it('opens exactly the endpoint :code guards', function (
    string $code, string $method, string $uri, array $payload, int $expect, ?string $extraCode = null
) {
    // Closed before the grant.
    expect(probeStatus(apiLogin('u8'), $method, $uri, $payload))->toBe(403);

    grantOnly($code);
    // Some routes are dual-gated (policies.* + approval_workflows.* on
    // workflow writes): the probe grants the companion code too so the test
    // proves the pair opens the endpoint rather than failing on the sibling.
    if ($extraCode !== null) {
        grantOnly($extraCode);
    }
    $token = apiLogin('u8');

    // Open after the grant.
    expect(probeStatus($token, $method, $uri, $payload))
        ->toBe($expect, "{$code} must allow {$method} {$uri}");

    // And the advertised capability list tells the frontend about it.
    $me = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->json('user');
    expect($me['permissions'])->toContain($code);
})->with([
    'admin.view' => ['admin.view', 'get', '/api/roles', [], 200],
    'admin.create' => ['admin.create', 'post', '/api/roles', ['name' => 'Probe Role A', 'permissions' => []], 201],
    'admin.update' => ['admin.update', 'patch', '/api/roles/SUPERVISOR', ['name' => 'Immediate Supervisor'], 200],
    'delegations.view' => ['delegations.view', 'get', '/api/delegations', [], 200],
    'delegations.create' => ['delegations.create', 'post', '/api/delegations', [], 422],
    'policies.edit_policy' => ['policies.edit_policy', 'post', '/api/workflows', [], 422, 'approval_workflows.create'],
    'reports.view' => ['reports.view', 'get', '/api/reports/zone', [], 200],
]);

it('lets admin.delete remove a custom role and nothing else', function () {
    throwawayRole();
    expect(probeStatus(apiLogin('u8'), 'delete', '/api/roles/DELETE_ME'))->toBe(403);

    grantOnly('admin.delete');
    $token = apiLogin('u8');

    expect(probeStatus($token, 'delete', '/api/roles/DELETE_ME'))->toBe(200);

    // Delete archives rather than removing the row, so the audit trail and any
    // past decision still resolve to a role that exists.
    expect(Role::find('DELETE_ME'))->not->toBeNull()
        ->and(Role::find('DELETE_ME')->active)->toBeFalse();

    // A role an approval workflow still requires is the one genuine refusal:
    // archiving it would leave that step with nobody able to act.
    expect(probeStatus($token, 'delete', '/api/roles/HR'))->toBe(422);
    expect(Role::find('HR')->active)->toBeTrue();
});

it('needs BOTH reports.view and reports.view_gender_reports for the gender report', function () {
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/reports/gender' && in_array('GET', $r->methods()));
    expect(collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')))
        ->toContain('permission:reports.view', 'permission:reports.view_gender_reports');

    // A separate employee per scenario, so the grants cannot accumulate.
    grantOnly('reports.view', 'e9');
    expect(probeStatus(apiLogin('u9'), 'get', '/api/reports/gender'))->toBe(403);

    grantOnly('reports.view_gender_reports', 'e10');
    expect(probeStatus(apiLogin('u10'), 'get', '/api/reports/gender'))->toBe(403);

    grantOnly('reports.view', 'e11');
    Role::where('id', 'PROBE_REPORTS_VIEW')->firstOrFail()->permissions()->attach(
        Permission::where('module', 'reports')->where('action', 'view_gender_reports')->value('id')
    );
    Cache::flush();

    expect(probeStatus(apiLogin('u11'), 'get', '/api/reports/gender'))->toBe(200);
});

it('does not let :code leak into a neighbouring endpoint', function (string $code, string $method, string $uri, array $payload) {
    if ($uri === '/api/roles/DELETE_ME') {
        throwawayRole();
    }
    grantOnly($code);
    $token = apiLogin('u8');

    expect(probeStatus($token, $method, $uri, $payload))
        ->toBe(403, "{$code} must not unlock {$method} {$uri}");
})->with([
    'admin.view cannot create roles' => ['admin.view', 'post', '/api/roles', ['name' => 'Should Not Exist', 'permissions' => []]],
    'admin.create cannot list the audit trail' => ['admin.create', 'get', '/api/audit', []],
    'admin.create cannot delete roles' => ['admin.create', 'delete', '/api/roles/DELETE_ME', []],
    'reports.view cannot read the audit trail' => ['reports.view', 'get', '/api/audit', []],
    'policies.view cannot author a policy' => ['policies.view', 'post', '/api/policies', []],
    'delegations.create cannot list delegations' => ['delegations.create', 'get', '/api/delegations', []],
    'admin.update cannot create roles' => ['admin.update', 'post', '/api/roles', ['name' => 'Should Not Exist', 'permissions' => []]],
    'admin.update cannot delete roles' => ['admin.update', 'delete', '/api/roles/DELETE_ME', []],
    'delegations.view cannot create delegations' => ['delegations.view', 'post', '/api/delegations', []],
    'reports.view_gender_reports cannot read zone reports' => ['reports.view_gender_reports', 'get', '/api/reports/zone', []],
]);

it('advertises only the granted code plus the self-service baseline', function () {
    grantOnly('admin.view');
    $token = apiLogin('u8');

    $me = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->json('user');

    expect($me['permissions'])
        ->toContain('admin.view')
        ->toContain(...PermissionService::SELF_SERVICE)
        ->not->toContain('admin.create', 'admin.update', 'admin.delete', 'requests.view_cross_zone');
});

it('revokes access the moment the permission is taken away', function () {
    grantOnly('admin.view');
    expect(probeStatus(apiLogin('u8'), 'get', '/api/roles'))->toBe(200);

    // Strip the code from the role, exactly as PATCH /roles/{id} does.
    Role::where('id', 'PROBE_ADMIN_VIEW')->firstOrFail()->permissions()->detach();
    Cache::flush();

    expect(probeStatus(apiLogin('u8'), 'get', '/api/roles'))->toBe(403);
});

it('never widens record scope when a permission carries no zone or department', function () {
    // reports.view says nothing about *which* records, so the request list must
    // stay own-record-only even for someone who can read every report.
    grantOnly('reports.view', 'e8');
    $token = apiLogin('u8');

    $ids = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$token}"])
        ->json('data.data.*.id');

    expect($ids)->toEqualCanonicalizing(LeaveRequest::where('employee_id', 'e8')->pluck('id')->all());
});

it('widens the request list only for the cross-zone code, not for org-wide read', function () {
    grantOnly('reports.view', 'e8');
    $plain = apiLogin('u8');
    expect($this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$plain}"])
        ->json('data.data'))->toHaveCount(LeaveRequest::where('employee_id', 'e8')->count());

    // Now add the one code that is allowed to widen record visibility.
    $role = Role::where('id', 'PROBE_REPORTS_VIEW')->firstOrFail();
    $role->permissions()->attach(
        Permission::where('module', 'requests')->where('action', 'view_cross_zone')->value('id')
    );
    Cache::flush();

    $wide = apiLogin('u8');
    $all = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$wide}"])->json('data.data');
    expect(count($all))->toBeGreaterThan(LeaveRequest::where('employee_id', 'e8')->count());
});
