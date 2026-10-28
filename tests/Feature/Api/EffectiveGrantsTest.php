<?php

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PermissionService::effectiveGrants() — the "may, where" resolver.
 *
 * codesFor() answers "may"; this answers "may, where" by pairing every code with
 * the zone/department of the assignment that granted it, and by refusing grants
 * outside their effective window. These tests are the regression net for that
 * behaviour now that the HTTP chain-diagnostic endpoint has been removed.
 */
function grantsFor(string $username, ?PermissionService $perms = null): array
{
    return ($perms ?? app(PermissionService::class))
        ->effectiveGrants(User::where('username', $username)->firstOrFail());
}

function sourcesFor(array $grants, string $code): array
{
    return array_values(array_column(array_filter(
        $grants,
        fn ($g) => $g['code'] === $code
    ), 'source'));
}

it('pairs each capability with the zone or department that actually granted it', function () {
    $grants = grantsFor('u5'); // Pemba zonal head, also head of d5

    $codes = array_column($grants, 'code');
    expect($codes)->toContain('requests.view', 'requests.approve_zone', 'reports.view')
        // A zonal head is not central: no organisation-wide codes.
        ->and($codes)->not->toContain('requests.view_cross_zone', 'staff.view_cross_zone');

    // The z4 zone grant is attributed to ZONAL_HEAD.
    $zoneScoped = array_values(array_filter($grants, fn ($g) => $g['zone_ids'] === ['z4']));
    expect($zoneScoped)->not->toBeEmpty()
        ->and($zoneScoped[0]['source'])->toBe('role:ZONAL_HEAD');

    // The d5 department grant is attributed to DEPT_HEAD, and stays separate.
    $deptScoped = array_values(array_filter($grants, fn ($g) => $g['department_ids'] === ['d5']));
    expect($deptScoped)->not->toBeEmpty()
        ->and($deptScoped[0]['source'])->toBe('role:DEPT_HEAD');

    // Resolving those same grants must land on the same scope the resolver uses.
    $scope = app(AccessScopeService::class)->forUser(User::where('username', 'u5')->firstOrFail());
    expect($scope->allowedZoneIds)->toBe(['z4'])
        ->and($scope->allowedDepartmentIds)->toBe(['d5'])
        ->and($scope->canViewAllZones)->toBeFalse()
        ->and($scope->ownRecordsOnly)->toBeFalse();

    // Capability without cross-zone codes must still not expose the whole org.
    $user = User::where('username', 'u5')->firstOrFail();
    $visible = app(AccessScopeService::class)->applyLeaveRequestScope(
        LeaveRequest::query(),
        $user
    )->count();

    expect($visible)->toBeGreaterThan(0)
        ->and($visible)->toBeLessThan(LeaveRequest::count());
});

it('enumerates the final authority profile instead of granting a wildcard', function () {
    $user = User::where('username', 'u1')->firstOrFail(); // DPP
    $perms = app(PermissionService::class);

    $codes = $perms->codesFor($user);

    // No sentinel. A wildcard silently absorbed every code added later, so the
    // RBAC matrix showed 7 codes for this role while it actually held all of
    // them, and can() short-circuited before ever reading module/action.
    expect($codes)->not->toContain('*');

    // What it holds is exactly what the matrix renders: every *implemented*
    // code in the catalog (planned codes are held on paper for the "every
    // code has a holder" invariant but never resolve, by construction).
    // Nothing invented, nothing withheld. No sentinel silently absorbs codes.
    // (93 since 2026_10_16_120000 pruned leave_documents.verify_public — the
    // frontend-only QR signal whose verification page no longer exists.)
    expect($codes)->not->toContain('*')
        ->and($codes)->toHaveCount(93);

    // Organisation-wide reach still comes from the explicit cross-zone codes,
    // so it survives the sentinel's removal.
    expect(app(AccessScopeService::class)->forUser($user)->canViewAllZones)->toBeTrue();

    // Grants are reported with a real source and real scope, not as one
    // organisation-wide blob that reads like a special case. Both sources are
    // legitimate here: the self-service codes arrive twice, once scoped to
    // the organisation through FINAL_AUTHORITY and once own-record scoped
    // through the SELF_SERVICE role that every employee now holds.
    $grants = grantsFor('u1');
    expect($grants)->not->toBeEmpty();
    foreach ($grants as $grant) {
        expect($grant['code'])->not->toBe('*')
            ->and($grant['source'])->toBeIn(['role:FINAL_AUTHORITY', 'role:SELF_SERVICE']);
    }
    expect(collect($grants)->pluck('source')->contains('role:FINAL_AUTHORITY'))->toBeTrue();

    // Organisation-wide reach still comes from the explicit cross-zone codes,
    // so it survives the sentinel's removal.
    expect(app(AccessScopeService::class)->forUser($user)->canViewAllZones)->toBeTrue();
});

it('does not absorb permission codes added after the fact', function () {
    $user = User::where('username', 'u1')->firstOrFail();
    $before = app(PermissionService::class)->codesFor($user);

    // A brand-new catalogued code with no grant row anywhere.
    $permission = Permission::create([
        'code' => 'reports.view_audit_trail',
        'module' => 'reports',
        'action' => 'view_audit_trail',
        'label' => 'view_audit_trail',
        'hint' => 'probe',
    ]);

    try {
        expect(app(PermissionService::class)->codesFor($user))->toEqual($before)
            ->and(app(PermissionService::class)->can($user, 'reports', 'view_audit_trail'))->toBeFalse();
    } finally {
        $permission->delete();
    }
});

it('drops a grant that falls outside its effective window', function () {
    $perms = app(PermissionService::class);
    $employee = Employee::findOrFail('e8');

    // Backdate a ZONAL_HEAD grant so it expires.
    EmployeeRole::create([
        'employee_id' => 'e8',
        'role_id' => 'ZONAL_HEAD',
        'scope_zone_id' => 'z1',
        'source' => 'manual_grant',
        'granted_by' => 'u3',
        'effective_from' => '2020-01-01',
        'effective_to' => '2020-12-31',
    ]);

    // The row exists but must not resolve.
    expect(EmployeeRole::where('employee_id', 'e8')->where('role_id', 'ZONAL_HEAD')->exists())->toBeTrue();
    expect($perms->activeAssignments('e8')->pluck('role_id'))->not->toContain('ZONAL_HEAD');

    $user = User::where('username', 'u8')->firstOrFail();
    expect($perms->codesFor($user))->not->toContain('requests.approve_zone');

    $grants = $perms->effectiveGrants($user);
    expect($grants)->toHaveCount(count(PermissionService::SELF_SERVICE))
        ->and(array_unique(array_column($grants, 'source')))->toBe(['role:SELF_SERVICE']);

    // No zone leaked into the self-service baseline.
    expect(array_merge(...array_column($grants, 'zone_ids')))->toBe([]);

    $scope = app(AccessScopeService::class)->forUser($user);
    expect($scope->allowedZoneIds)->toBe([])
        ->and($scope->canViewAllZones)->toBeFalse()
        ->and($scope->ownRecordsOnly)->toBeTrue();
});

it('honours a grant that only becomes effective today', function () {
    $perms = app(PermissionService::class);

    EmployeeRole::create([
        'employee_id' => 'e8',
        'role_id' => 'ZONAL_HEAD',
        'scope_zone_id' => 'z2',
        'source' => 'manual_grant',
        'granted_by' => 'u3',
        'effective_from' => now()->toDateString(),
        'effective_to' => null,
    ]);

    expect($perms->activeAssignments('e8')->pluck('role_id'))->toContain('ZONAL_HEAD');

    $scope = app(AccessScopeService::class)->forUser(User::where('username', 'u8')->firstOrFail());
    expect($scope->allowedZoneIds)->toBe(['z2']);
});

it('hands the self-service floor out through a real role, revocably', function () {
    $perms = app(PermissionService::class);
    $user = User::where('username', 'u8')->firstOrFail();

    // e8 holds SELF_SERVICE like every other active employee, so the four
    // codes are an ordinary matrix grant that an admin can take away.
    expect($perms->activeAssignments('e8')->pluck('role_id'))->toContain(PermissionService::SELF_SERVICE_ROLE);

    $grants = $perms->effectiveGrants($user);
    expect(array_column($grants, 'code'))->toEqualCanonicalizing(PermissionService::SELF_SERVICE);
    expect(array_unique(array_column($grants, 'source')))->toBe(['role:SELF_SERVICE']);

    // Own-record only, but the capability is present.
    expect($perms->can($user, 'requests', 'view'))->toBeTrue()
        ->and($perms->can($user, 'requests', 'approve'))->toBeFalse();

    // Taking the role away really does take the codes away. This is the whole
    // point of moving off the merge: previously the baseline was re-added on
    // every read, so no admin action could ever revoke it.
    $hr = apiLogin('u3');
    $this->deleteJson('/api/org/employees/e8/roles/'.PermissionService::SELF_SERVICE_ROLE, [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk();

    expect($perms->activeAssignments('e8'))->toBeEmpty()
        ->and($perms->codesFor($user))->toBe([])
        ->and($perms->effectiveGrants($user))->toBe([])
        ->and($perms->can($user, 'requests', 'view'))->toBeFalse();
});

it('invalidates the memoised codes the moment a grant changes', function () {
    $perms = app(PermissionService::class);
    $user = User::where('username', 'u8')->firstOrFail();

    // Warm the cache, then confirm the grant endpoint drops it immediately
    // rather than letting a stale answer survive for the 60s TTL.
    $perms->codesFor($user);
    expect(Cache::has('perms:u8'))->toBeTrue();

    $hr = apiLogin('u3');
    $this->postJson('/api/org/employees/e8/roles/SUPERVISOR', [], ['Authorization' => "Bearer {$hr}"])
        ->assertOk();

    expect(Cache::has('perms:u8'))->toBeFalse();

    // And the freshly resolved codes already include the new role.
    expect($perms->codesFor($user))->toContain('requests.approve');
});

it('reports no orphan grants and no codes outside the catalog', function () {
    // role_permissions is keyed by permission_id with a real FK, so a granted
    // code always resolves to a catalog entry. Assert the invariant anyway: it is
    // what makes "what can this role do?" answerable.
    $orphans = DB::table('role_permissions as rp')
        ->leftJoin('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->whereNull('p.id')
        ->count();
    expect($orphans)->toBe(0);

    // Same for employee_roles -> roles.
    $dangling = DB::table('employee_roles as er')
        ->leftJoin('roles as r', 'r.id', '=', 'er.role_id')
        ->whereNull('r.id')
        ->count();
    expect($dangling)->toBe(0);

    // Every code the resolver hands out must be catalogued. There is no wildcard
    // escape hatch any more, so a code the catalog does not know is a bug.
    foreach (['u5', 'u8', 'u3', 'u1'] as $username) {
        foreach (app(PermissionService::class)->codesFor(User::where('username', $username)->firstOrFail()) as $code) {
            expect($code)->not->toBe('*');
            expect(Permission::where('code', $code)->exists())->toBeTrue();
        }
    }
});

it('keeps the permission catalog canonical', function () {
    // REGEXP is MySQL-only, so evaluate the pattern in PHP for portability.
    $pattern = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';
    $malformed = Permission::pluck('code')
        ->reject(fn ($code) => preg_match($pattern, (string) $code) === 1)
        ->values()
        ->all();
    expect($malformed)->toBe([]);

    // The self-service baseline must be catalogued too, or it would be invisible
    // to any admin reviewing what an employee can do.
    foreach (PermissionService::SELF_SERVICE as $code) {
        expect(Permission::where('code', $code)->exists())->toBeTrue();
    }
});

it('does not let a scoped role_id grant imply organisation-wide reach', function () {
    // Regression guard: role assignment scope and permission scope are separate
    // axes. A department head holds DEPT_HEAD, which carries no cross-zone code,
    // so their reads stay inside the department no matter how the role is scoped.
    $hr = apiLogin('u3');
    $employee = Employee::findOrFail('e3'); // heads d1, d6, d7, d8

    $deptHeadCodes = app(PermissionService::class)->codesForRole('DEPT_HEAD');
    expect($deptHeadCodes)->not->toContain('requests.view_cross_zone');

    $scope = app(AccessScopeService::class)->forUser(User::where('username', 'u3')->firstOrFail());
    // HR itself does hold the cross-zone codes, so check the role in isolation.
    expect(Role::find('DEPT_HEAD')->is_final_authority)->toBeFalse();
    expect($deptHeadCodes)->toContain('requests.view');

    $this->getJson('/api/requests?scope=all', ['Authorization' => "Bearer {$hr}"])->assertOk();
});
