<?php

use App\Models\CaseHandover;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\PermissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Zone / department data-visibility enforcement.
 *
 * Security rule under test: "Regional offices only access staff and requests
 * within their own zone unless central permission is granted."
 *
 * Seeded cast:
 *   e1/u1  DPP, FINAL_AUTHORITY -> every catalogued code, enumerated
 *   e2/u2  DEPT_HEAD scoped to d3 -> department grant only (no zone grant)
 *   e3/u3  HR (central) -> requests/staff/reports.view_cross_zone
 *   e4/u4  ZONAL_HEAD scoped to z2 -> Unguja North
 *   e5/u5  ZONAL_HEAD scoped to z4 -> Pemba (no cross-zone)
 *   e7/u7  SUPERVISOR, no scope grant -> own records only
 *   e8/u8  no roles at all -> holds no requests.view
 *
 * Requests are addressed by `ref` because leave_requests.id is auto-increment.
 */

/** Pemba (z4) — e9. */
const PEMBA_REF = 'LVE-2026-0153';

/** Unguja North (z2) — e4. */
const NORTH_HEAD_REF = 'LVE-2026-0159';

/** Unguja North (z2) — e8, approved. */
const NORTH_STAFF_REF = 'LVE-2026-0150';

/** Unguja North (z2) — e8, submitted. */
const NORTH_STAFF_REF_2 = 'LVE-2026-0162';

/** Headquarters (z1) — e1 (DPP). */
const HQ_DPP_REF = 'LVE-2026-0167';

/** Headquarters (z1) — e7, approved. */
const HQ_STAFF_REF = 'LVE-2026-0147';

function requestId(string $ref): string
{
    return (string) LeaveRequest::where('ref', $ref)->value('id');
}

/** @return array<int,string> */
function refsFromResponse($response): array
{
    return collect($response->json('data.data'))->pluck('ref')->all();
}

it('gives cross-zone permissions only to central roles', function () {
    $hr = apiLogin('u3');
    $zonal = apiLogin('u5');
    $supervisor = apiLogin('u7');

    $this->getJson('/api/me', ['Authorization' => "Bearer {$hr}"])
        ->assertOk()
        ->assertJsonPath('user.access.can_view_cross_zone', true)
        ->assertJsonPath('user.access.can_view_cross_zone_staff', true)
        ->assertJsonPath('user.access.can_view_cross_zone_reports', true);

    $this->getJson('/api/me', ['Authorization' => "Bearer {$zonal}"])
        ->assertOk()
        ->assertJsonPath('user.access.can_view_cross_zone', false)
        ->assertJsonPath('user.access.request_scope.allowed_zone_ids', ['z4'])
        ->assertJsonPath('user.access.request_scope.own_records_only', false);

    // A user with no zone/department grant is denied by default.
    $this->getJson('/api/me', ['Authorization' => "Bearer {$supervisor}"])
        ->assertOk()
        ->assertJsonPath('user.access.can_view_cross_zone', false)
        ->assertJsonPath('user.access.request_scope.own_records_only', true);
});

it('restricts a Pemba zonal head to Pemba requests even with scope=all', function () {
    $token = apiLogin('u5');

    $response = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    $refs = refsFromResponse($response);

    expect($refs)->not->toBeEmpty();
    // Every returned row must belong to the Pemba office.
    foreach (LeaveRequest::whereIn('ref', $refs)->with('employee')->get() as $request) {
        expect($request->employee->zone_id)->toBe('z4');
    }

    expect($refs)->toContain(PEMBA_REF);
    expect($refs)->not->toContain(HQ_DPP_REF);      // e1, z1
    expect($refs)->not->toContain(NORTH_HEAD_REF);  // e4, z2
    expect($response->json('data.total'))->toBeLessThan(LeaveRequest::count());
});

it('restricts an Unguja North zonal head to that zone', function () {
    $token = apiLogin('u4');

    $refs = refsFromResponse(
        $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$token}"])->assertOk()
    );

    expect($refs)->toEqualCanonicalizing([NORTH_HEAD_REF, NORTH_STAFF_REF, NORTH_STAFF_REF_2]);
    expect($refs)->not->toContain(HQ_DPP_REF); // z1
    expect($refs)->not->toContain(PEMBA_REF);  // z4
});

it('blocks cross-zone read by direct ID', function () {
    $pemba = apiLogin('u5');

    // Owned by e1 (z1) and e4 (z2) — both outside Pemba scope.
    $this->getJson('/api/requests/'.requestId(HQ_DPP_REF), ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);
    $this->getJson('/api/requests/'.requestId(NORTH_HEAD_REF), ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);

    // Owned by e9 (z4) — inside Pemba scope.
    $this->getJson('/api/requests/'.requestId(PEMBA_REF), ['Authorization' => "Bearer {$pemba}"])
        ->assertOk();

    // The approval-chain preview leaks named assignees, so it is scoped too.
    $this->getJson('/api/requests/'.requestId(HQ_DPP_REF).'/workflow', ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);
    $this->getJson('/api/requests/'.requestId(PEMBA_REF).'/workflow', ['Authorization' => "Bearer {$pemba}"])
        ->assertOk();
});

it('lets central HR read across zones', function () {
    $hr = apiLogin('u3');

    $response = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$hr}"])
        ->assertOk();

    $refs = refsFromResponse($response);

    expect($response->json('data.total'))->toBe(LeaveRequest::count());
    expect($refs)->toContain(PEMBA_REF, HQ_DPP_REF, NORTH_HEAD_REF);

    $this->getJson('/api/requests/'.requestId(HQ_DPP_REF), ['Authorization' => "Bearer {$hr}"])->assertOk();
    $this->getJson('/api/requests/'.requestId(NORTH_HEAD_REF), ['Authorization' => "Bearer {$hr}"])->assertOk();
});

it('denies the DPP nothing — the enumerated view_cross_zone codes retain organisation-wide reach', function () {
    // The `['*']` sentinel used to be what made this pass. It is gone; the DPP now
    // reaches every zone through its three explicit view_cross_zone grants, so
    // this test now proves the enumerated profile is genuinely equivalent rather
    // than proving a wildcard short-circuits the scope layer.
    $dpp = apiLogin('u1');

    $response = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$dpp}"])
        ->assertOk();

    expect($response->json('data.total'))->toBe(LeaveRequest::count());

    expect(app(PermissionService::class)->codesForRole('FINAL_AUTHORITY'))
        ->toContain('requests.view_cross_zone', 'staff.view_cross_zone', 'reports.view_cross_zone');
});

it('scopes a department head to their department, not their zone', function () {
    // e2 is DEPT_HEAD of d3 and sits in zone z1. Nobody else is in d3, so the
    // department grant must resolve to their own records only — proving that
    // sharing a zone does not widen visibility.
    $token = apiLogin('u2');

    $response = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    expect($response->json('data.total'))->toBe(0);
});

it('prevents a supervisor without a zone grant from enumerating colleagues', function () {
    // e7 holds requests.view via SUPERVISOR but no zone/department grant, so
    // deny-by-default applies.
    $token = apiLogin('u7');

    $response = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    $refs = refsFromResponse($response);

    // e7 owns exactly three seeded requests (r1, r9, r12).
    expect($refs)->toHaveCount(3);
    foreach (LeaveRequest::whereIn('ref', $refs)->get() as $request) {
        expect($request->employee_id)->toBe('e7');
    }
    expect($refs)->not->toContain(PEMBA_REF);
    expect($response->json('data.total'))->toBeLessThan(LeaveRequest::count());

    // Default (own-records) scope returns the same set.
    $own = refsFromResponse($this->getJson('/api/requests?per_page=100', ['Authorization' => "Bearer {$token}"])->assertOk());
    expect($own)->toEqualCanonicalizing($refs);

    // Direct ID access to a colleague is still refused.
    $this->getJson('/api/requests/'.requestId(PEMBA_REF), ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);
});

it('lets a plain employee see only their own leave, never colleagues', function () {
    // e8 holds no role at all. Baseline self-service lets them read and create
    // requests; the scope layer is what keeps the list to their own records.
    $token = apiLogin('u8'); // e8 owns LVE-2026-0150 and LVE-2026-0162 (both z2)

    $me = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();
    expect($me->json('user.permissions'))->toContain('requests.view', 'requests.create');
    expect($me->json('user.permissions'))->not->toContain('requests.approve', 'requests.export');
    expect($me->json('user.permissions'))->not->toContain('admin.view', 'reports.view');
    expect($me->json('user.access.request_scope.own_records_only'))->toBeTrue();

    $response = $this->getJson('/api/requests?scope=all&per_page=100', ['Authorization' => "Bearer {$token}"])
        ->assertOk();

    $refs = refsFromResponse($response);

    expect($refs)->toEqualCanonicalizing([NORTH_STAFF_REF, NORTH_STAFF_REF_2]);
    expect($refs)->not->toContain(PEMBA_REF, HQ_DPP_REF, HQ_STAFF_REF);

    // A colleague in the very same zone is still out of reach.
    $this->getJson('/api/requests/'.requestId(NORTH_HEAD_REF), ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403);

    // Admin and report surfaces stay closed.
    $this->getJson('/api/roles', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    $this->getJson('/api/audit', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    $this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
});

it('scopes the staff directory to zone for regional offices', function () {
    $pemba = apiLogin('u5');
    $hr = apiLogin('u3');

    $pembaStaff = collect($this->getJson('/api/org/employees', ['Authorization' => "Bearer {$pemba}"])
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($pembaStaff)->toEqualCanonicalizing(['e5', 'e9']);
    expect($pembaStaff)->not->toContain('e1', 'e8');

    // Central HR sees the whole organisation.
    $hrStaff = collect($this->getJson('/api/org/employees', ['Authorization' => "Bearer {$hr}"])
        ->assertOk()->json('data'))->pluck('id')->all();

    expect($hrStaff)->toHaveCount(Employee::count());
});

it('scopes reports to the caller zone and requires the granular gender gate', function () {
    $north = apiLogin('u4'); // z2
    $hr = apiLogin('u3');

    $zones = collect($this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$north}"])
        ->assertOk()->json('data'));

    expect($zones)->not->toBeEmpty();
    foreach ($zones as $row) {
        expect($row['zone_id'])->toBe('z2');
    }

    // Org-wide reporting requires reports.view_cross_zone.
    $hrZones = collect($this->getJson('/api/reports/zone', ['Authorization' => "Bearer {$hr}"])
        ->assertOk()->json('data'))->pluck('zone_id')->all();

    expect($hrZones)->toContain('z1', 'z2');

    // Gender report stays denied for a regional office.
    $this->getJson('/api/reports/gender', ['Authorization' => "Bearer {$north}"])->assertStatus(403);
    $this->getJson('/api/reports/gender', ['Authorization' => "Bearer {$hr}"])->assertOk();
});

it('scopes upcoming and monthly aggregates to the caller zone', function () {
    $pemba = apiLogin('u5');
    $hr = apiLogin('u3');

    $upcoming = collect($this->getJson('/api/reports/upcoming', ['Authorization' => "Bearer {$pemba}"])
        ->assertOk()->json('data'))->pluck('zone_id')->all();

    foreach ($upcoming as $zoneId) {
        expect($zoneId)->toBe('z4');
    }

    // Monthly aggregates are join-based; a Pemba caller must not see
    // organisation-wide day counts.
    $pembaMonthly = collect($this->getJson('/api/reports/monthly', ['Authorization' => "Bearer {$pemba}"])
        ->assertOk()->json('data'))->pluck('days')->sum();
    $hrMonthly = collect($this->getJson('/api/reports/monthly', ['Authorization' => "Bearer {$hr}"])
        ->assertOk()->json('data'))->pluck('days')->sum();

    expect($pembaMonthly)->toBeLessThan($hrMonthly);
});

it('refuses handover reads outside the caller zone', function () {
    $pemba = apiLogin('u5');

    // Owned by e7 (z1) — outside Pemba scope.
    $this->getJson('/api/handovers/'.requestId(HQ_STAFF_REF), ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);
});

it('refuses to propose a handover on a request the caller cannot see', function () {
    // propose() was the one handover action with no authorize() call, so any
    // authenticated employee could hand a live court case to an arbitrary
    // colleague by naming someone else's request id.
    $pemba = apiLogin('u5'); // z4
    $target = requestId(HQ_STAFF_REF); // owned in z1, outside Pemba scope

    $this->postJson("/api/handovers/{$target}/propose", ['to_employee_id' => 'e7'], ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);

    // Nothing was written.
    expect(CaseHandover::where('request_id', $target)->where('to_employee_id', 'e7')->exists())->toBeFalse();

    // Someone who can see the request may still propose one, and the proposer is
    // the caller rather than a fallback to the requester. One handover row is
    // produced per open court case, so `data` is a list.
    $hr = apiLogin('u3');
    $created = $this->postJson("/api/handovers/{$target}/propose", ['to_employee_id' => 'e7'], ['Authorization' => "Bearer {$hr}"]);
    $created->assertStatus(201);
    expect($created->json('data.0.from.id'))->toBe('e3')
        ->and($created->json('data.0.to.id'))->toBe('e7');
});

it('scopes the eligibility summary to what the caller may read', function () {
    // orgSummary() used to aggregate every active employee in the organisation,
    // so a Pemba clerk was told headcount and eligible totals for every zone.
    $pemba = apiLogin('u5');
    $pembaOrg = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$pemba}"])->json('org');

    expect($pembaOrg['scoped'])->toBeTrue();

    $allEmployees = Employee::where('is_active', true)->count();
    $pembaZones = collect($pembaOrg['per_zone'])->pluck('zone_id')->all();
    $pembaDepts = collect($pembaOrg['per_department'])->pluck('department_id')->all();

    // No zone or department outside the caller's own appears in the roll-up.
    $ownZoneIds = Employee::find('e5')->zone_id;
    foreach ($pembaZones as $zoneId) {
        expect($zoneId)->toBe($ownZoneIds);
    }
    expect($pembaOrg['total_employees'])->toBeLessThan($allEmployees);

    // Central HR keeps the whole-organisation view.
    $hr = apiLogin('u3');
    $hrOrg = $this->getJson('/api/eligibility', ['Authorization' => "Bearer {$hr}"])->json('org');
    expect($hrOrg['scoped'])->toBeFalse()
        ->and($hrOrg['total_employees'])->toBe($allEmployees);
});

it('refuses document uploads against another zone request', function () {
    Storage::fake('local');

    $north = apiLogin('u4');  // z2 — no documents.create
    $pemba = apiLogin('u5');  // z4 — no documents.create
    $hr = apiLogin('u3');     // central HR

    $file = ['file' => UploadedFile::fake()->create('cert.pdf', 64, 'application/pdf')];

    $this->postJson('/api/requests/'.requestId(PEMBA_REF).'/documents', $file, ['Authorization' => "Bearer {$north}"])
        ->assertStatus(403);
    $this->postJson('/api/requests/'.requestId(HQ_DPP_REF).'/documents', $file, ['Authorization' => "Bearer {$pemba}"])
        ->assertStatus(403);

    // Central HR retains organisation-wide document administration.
    $this->postJson('/api/requests/'.requestId(PEMBA_REF).'/documents', $file, ['Authorization' => "Bearer {$hr}"])
        ->assertStatus(201);
});
