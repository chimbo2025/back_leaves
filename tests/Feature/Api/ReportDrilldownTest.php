<?php

/**
 * Office summary cards and their drill-down detail tables.
 *
 * The contract under test: every card total and every detail row set comes
 * from the same builders with the same filters and scope, so totals always
 * equal their details. Detail endpoints paginate server-side, whitelist
 * sorting, cap per_page, preserve filters in links, and redact narrative
 * and medical fields.
 */
function drillHeaders(string $username): array
{
    return ['Authorization' => 'Bearer '.apiLogin($username)];
}

function detailTotal(string $url, array $headers): array
{
    // Walk every page like a client would and sum what the server returned.
    $days = 0;
    $count = 0;
    $meta = null;
    $pageUrl = $url.(str_contains($url, '?') ? '&' : '?').'per_page=100';

    do {
        $res = test()->getJson($pageUrl, $headers)->assertOk()->json();
        $meta = $res['meta'];
        $count += count($res['data']);
        foreach ($res['data'] as $row) {
            $days += (float) ($row['days'] ?? 0);
        }
        $pageUrl = $res['links']['next'];
    } while ($pageUrl !== null);

    return [$count, $days, $meta];
}

it('keeps approved-days, pending and logged totals equal to their detail rows', function () {
    $h = drillHeaders('u3'); // HR: cross-zone, sees everything

    $summary = $this->getJson('/api/reports/summary', $h)->assertOk()->json('data');

    [$pendingCount] = detailTotal('/api/reports/pending/details', $h);
    expect($summary['cards']['pending']['total'])->toBe($pendingCount);

    [$loggedCount] = detailTotal('/api/reports/requests/details', $h);
    expect($summary['cards']['requests_logged']['total'])->toBe($loggedCount);

    [$approvedCount, $approvedDays] = detailTotal('/api/reports/approved-days/details', $h);
    expect((float) $summary['cards']['approved_days']['total'])->toBe((float) $approvedDays);
    expect($approvedCount)->toBeGreaterThan(0);

    // Gender split adds back up to the approved total (u3 may see gender).
    expect($summary['cards']['gender'])->not->toBeNull();
    expect((float) $summary['cards']['gender']['F'] + (float) $summary['cards']['gender']['M'])->toBe((float) $approvedDays);

    [$fCount, $fDays] = detailTotal('/api/reports/gender/F/details', $h);
    [$mCount, $mDays] = detailTotal('/api/reports/gender/M/details', $h);
    expect($fDays + $mDays)->toBe($approvedDays);
    expect($fCount + $mCount)->toBe($approvedCount);
});

it('keeps zone, type and status aggregates equal to their detail rows', function () {
    $h = drillHeaders('u3');

    $summary = $this->getJson('/api/reports/summary', $h)->assertOk()->json('data');

    foreach ($summary['by_zone'] as $zone) {
        [$count, $days] = detailTotal("/api/reports/leave-usage-by-zone/{$zone['zone_id']}/details", $h);
        expect($count)->toBe($zone['requests']);
        expect((float) $days)->toBe((float) $zone['days']);
    }

    foreach ($summary['by_type'] as $type) {
        [$count, $days] = detailTotal("/api/reports/leave-types/{$type['leave_type_id']}/details", $h);
        expect((float) $days)->toBe((float) $type['days']);
        expect($count)->toBeGreaterThan(0);
    }

    $statusTotal = 0;
    foreach ($summary['status_mix'] as $mix) {
        [$count] = detailTotal("/api/reports/status/{$mix['status']}/details", $h);
        expect($count)->toBe($mix['count']);
        $statusTotal += $count;
    }
    expect($statusTotal)->toBe($summary['cards']['requests_logged']['total']);
});

it('keeps staff totals equal to staff detail rows', function () {
    $h = drillHeaders('u3');

    $summary = $this->getJson('/api/reports/summary', $h)->assertOk()->json('data');

    $res = $this->getJson('/api/reports/staff/details?per_page=100', $h)->assertOk()->json();
    expect($res['meta']['total'])->toBe($summary['cards']['staff']['total']);
    expect(count($res['data']))->toBe($res['meta']['total']);

    foreach ($res['data'] as $row) {
        expect($row)->toHaveKeys(['id', 'full_name', 'department', 'zone', 'hire_date'])
            ->and($row)->not->toHaveKeys(['payroll_no', 'email']);
    }
});

it('applies date filters consistently to summary and details', function () {
    // September holds seeded approved leave (r2 Sep 14–18, r5 Sep 20–21).
    $h = drillHeaders('u3');
    $qs = 'from=2026-09-01&to=2026-09-30';

    $summary = $this->getJson("/api/reports/summary?{$qs}", $h)->assertOk()->json('data');
    [$count, $days] = detailTotal("/api/reports/approved-days/details?{$qs}", $h);
    expect((float) $summary['cards']['approved_days']['total'])->toBe((float) $days);

    // Every returned row really overlaps the window.
    $res = $this->getJson("/api/reports/approved-days/details?{$qs}&per_page=100", $h)->assertOk()->json();
    foreach ($res['data'] as $row) {
        expect($row['start_date'])->toBeLessThanOrEqual('2026-09-30');
        expect($row['end_date'] ?? '9999-12-31')->toBeGreaterThanOrEqual('2026-09-01');
    }

    // Meta echoes the filters so callers can prove parity.
    expect($res['meta']['filters']['from'])->toBe('2026-09-01');
    expect($res['meta']['filters']['to'])->toBe('2026-09-30');
    expect($count)->toBeGreaterThan(0);
});

it('never lets department or zone scopes be bypassed', function () {
    // e4 heads d4 in z2: sees z2/d4 rows only.
    $h = drillHeaders('u4');

    $summary = $this->getJson('/api/reports/summary', $h)->assertOk()->json('data');
    $zoneIds = array_column($summary['by_zone'], 'zone_id');
    expect($zoneIds)->toBe(['z2']);

    // Asking for another zone yields an empty set, not someone else's rows.
    $res = $this->getJson('/api/reports/leave-usage-by-zone/z1/details', $h)->assertOk()->json();
    expect($res['meta']['total'])->toBe(0);
    expect($res['data'])->toBe([]);

    // Same for a direct employee filter outside scope (e7 is z1/d2).
    $res = $this->getJson('/api/reports/requests/details?employee_id=e7', $h)->assertOk()->json();
    expect($res['meta']['total'])->toBe(0);

    // And the same query as HR is non-empty, proving the rows exist.
    $hr = drillHeaders('u3');
    expect($this->getJson('/api/reports/requests/details?employee_id=e7', $hr)->json('meta.total'))->toBeGreaterThan(0);
});

it('respects the gender permission on totals and details', function () {
    // u6 supervises but holds no gender code.
    $h = drillHeaders('u6');

    $summary = $this->getJson('/api/reports/summary', $h)->assertOk()->json('data');
    expect($summary['cards']['gender'])->toBeNull();

    $this->getJson('/api/reports/gender/F/details', $h)->assertForbidden();
    $this->getJson('/api/reports/gender/M/details', $h)->assertForbidden();
    $this->getJson('/api/reports/gender/X/details', drillHeaders('u3'))->assertStatus(422);
});

it('redacts narrative and medical fields from detail rows', function () {
    // e8's sick leave carries a malaria reason: the row must exist (sick
    // leave is reportable) without any of its narrative fields.
    $h = drillHeaders('u3');

    $res = $this->getJson('/api/reports/approved-days/details?employee_id=e8', $h)->assertOk()->json();
    expect($res['meta']['total'])->toBeGreaterThan(0);

    $body = json_encode($res);
    expect($body)->not->toContain('Malaria');
    foreach ($res['data'] as $row) {
        expect($row)->not->toHaveKeys(['reason', 'exception_reason', 'medical_required', 'is_emergency_exception']);
    }

    // Staff rows never carry payroll or email; gender only with the code.
    $staff = $this->getJson('/api/reports/staff/details?per_page=100', $h)->assertOk()->json();
    expect(json_encode($staff))->not->toContain('@');
    foreach ($staff['data'] as $row) {
        expect($row)->not->toHaveKeys(['payroll_no', 'email']);
    }
});

it('paginates server-side with correct metadata, links, caps and sorts', function () {
    $h = drillHeaders('u3');

    // per_page above the maximum is clamped, not honored.
    $res = $this->getJson('/api/reports/requests/details?per_page=500', $h)->assertOk()->json();
    expect($res['meta']['per_page'])->toBe(200);

    // per_page below the minimum is rejected.
    $this->getJson('/api/reports/requests/details?per_page=0', $h)->assertStatus(422);

    // Unknown sort columns fall back to the default instead of erroring or
    // injecting ORDER BY on an arbitrary column.
    $this->getJson('/api/reports/requests/details?sort=password&direction=desc', $h)->assertOk();

    // A real sort orders rows.
    $asc = $this->getJson('/api/reports/requests/details?sort=days&direction=asc&per_page=100', $h)->assertOk()->json();
    $days = array_column($asc['data'], 'days');
    $sorted = $days;
    sort($sorted);
    expect($days)->toBe($sorted);

    // Pagination metadata is coherent and links preserve the filters.
    $paged = $this->getJson('/api/reports/approved-days/details?from=2026-01-01&to=2026-12-31&per_page=1&page=2', $h)->assertOk()->json();
    expect($paged['meta']['current_page'])->toBe(2);
    expect($paged['meta']['per_page'])->toBe(1);
    expect($paged['meta']['from'])->toBe(2);
    expect($paged['meta']['to'])->toBe(2);
    expect($paged['links']['prev'])->toContain('from=2026-01-01');
    expect($paged['links']['prev'])->toContain('page=1');
    expect($paged['links']['next'])->toContain('page=3');
});
