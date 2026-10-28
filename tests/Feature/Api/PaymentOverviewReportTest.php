<?php

use App\Models\AuditLog;
use App\Models\LeavePaymentCase;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Payment Overview reporting: visibility, categories, redaction.
 *
 * Scene (each test rebuilds it; RefreshDatabase isolates):
 * - e7/lt1  paid               (full flow to paid)
 * - e8/lt1  under_hr_review    (created + submitted)
 * - e10/lt1 hr_rejected        (created + submitted + rejected by u2)
 * - e11/lt1 processing         (flow to receive)
 * - e6/lt1  sent_to_accounting (flow to send)
 * - e4/lt1  hr_approved        (flow to approve)
 * - e12/lt5 cancelled          (created + cancelled by creator)
 * - e11/lt1 approved arrived leave (direct fixture, past dates)
 *
 * All cases: encashment, salary 1_200_000, 10 days → 400,000 each when
 * eligible (lt5/unpaid: amounts may be absent — assertions read the DB).
 */
function payHeaders(string $username): array
{
    return ['Authorization' => 'Bearer '.apiLogin($username)];
}

function makePayCase(string $employeeId, string $leaveTypeId = 'lt1'): int
{
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => $employeeId,
        'leave_type_id' => $leaveTypeId,
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], payHeaders('u3'))->assertStatus(201)->json('data');

    return $created['id'];
}

function grantHrToE2(): void
{
    test()->postJson('/api/org/employees/e2/roles/HR', [], payHeaders('u3'))->assertOk();
}

function grantAccountingToE10(): void
{
    test()->postJson('/api/org/employees/e10/roles/ACCOUNTING', [], payHeaders('u1'))->assertOk();
}

/** Drive one case to paid through the full segregation-of-duties flow. */
function driveToPaid(int $caseId): void
{
    test()->postJson("/api/leave-payments/{$caseId}/submit", [], payHeaders('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], payHeaders('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], payHeaders('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/receive", [], payHeaders('u10'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/paid", [], payHeaders('u10'))->assertOk();
}

function paymentScene(): array
{
    // One-off cross-employee grants; every flow below reuses them.
    grantHrToE2();
    grantAccountingToE10();

    $paid = makePayCase('e7');
    driveToPaid($paid);

    $pending = makePayCase('e8');
    test()->postJson("/api/leave-payments/{$pending}/submit", [], payHeaders('u3'))->assertOk();

    $rejected = makePayCase('e10');
    test()->postJson("/api/leave-payments/{$rejected}/submit", [], payHeaders('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$rejected}/reject", ['reason' => 'Entitlement exhausted.'], payHeaders('u2'))->assertOk();

    $processing = makePayCase('e11');
    test()->postJson("/api/leave-payments/{$processing}/submit", [], payHeaders('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$processing}/approve", [], payHeaders('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$processing}/send-to-accounting", [], payHeaders('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$processing}/receive", [], payHeaders('u10'))->assertOk();

    $sent = makePayCase('e6');
    test()->postJson("/api/leave-payments/{$sent}/submit", [], payHeaders('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$sent}/approve", [], payHeaders('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$sent}/send-to-accounting", [], payHeaders('u2'))->assertOk();

    $approved = makePayCase('e4');
    test()->postJson("/api/leave-payments/{$approved}/submit", [], payHeaders('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$approved}/approve", [], payHeaders('u2'))->assertOk();

    $cancelled = makePayCase('e12', 'lt5');
    test()->postJson("/api/leave-payments/{$cancelled}/cancel", [], payHeaders('u3'))->assertOk();

    // e11 took annual leave in the past: arrived, approved, unpaid.
    LeaveRequest::create([
        'ref' => 'LVE-PAY-1',
        'employee_id' => 'e11',
        'leave_type_id' => 'lt1',
        'policy_id' => 'pol1',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'days' => 5,
        'status' => 'approved',
    ]);

    return compact('paid', 'pending', 'rejected', 'processing', 'sent', 'approved', 'cancelled');
}

function paymentCategoryTotal(string $url, array $headers): array
{
    $ids = [];
    $pageUrl = $url.(str_contains($url, '?') ? '&' : '?').'per_page=100';

    do {
        $res = test()->getJson($pageUrl, $headers)->assertOk()->json();
        foreach ($res['data'] as $row) {
            $ids[] = $row['payment_case']['id'] ?? $row['id'] ?? json_encode($row);
        }
        $pageUrl = $res['links']['next'];
    } while ($pageUrl !== null);

    return [$res['meta']['total'], $ids, $res];
}

it('keeps the payment summary equal to its category details', function () {
    $scene = paymentScene();
    $h = payHeaders('u3');

    $summary = test()->getJson('/api/reports/payment/summary', $h)->assertOk()->json('data');
    $cards = $summary['cards'];

    expect($cards['paid']['total'])->toBe(1);
    expect($cards['pending_hr']['total'])->toBe(1);
    expect($cards['pending_accounting']['total'])->toBe(2);
    expect($cards['processing']['total'])->toBe(1);
    expect($cards['rejected']['total'])->toBe(1);

    // Pair buckets: definitional overlaps hold (subsets of eligible_unpaid).
    expect($cards['eligible_unpaid']['total'])->toBeGreaterThanOrEqual($cards['without_payment_request']['total']);
    expect($cards['eligible_unpaid']['total'])->toBeGreaterThanOrEqual($cards['leave_taken_unpaid']['total']);
    expect($cards['without_payment_request']['total'])->toBeGreaterThan(0);
    expect($cards['leave_taken_unpaid']['total'])->toBeGreaterThan(0);
    expect($cards['eligible_no_leave']['total'])->toBeGreaterThan(0);

    // e11/lt1: arrived approved leave + processing (unpaid) case.
    [$takeTotal, $takeIds] = paymentCategoryTotal('/api/reports/payment/leave-taken-unpaid', $h);
    expect($takeTotal)->toBe($cards['leave_taken_unpaid']['total']);
    $e11lt1 = LeavePaymentCase::where('employee_id', 'e11')->where('leave_type_id', 'lt1')->firstOrFail();
    expect($takeIds)->toContain($e11lt1->id);

    // e7/lt1 is paid: excluded from every unpaid bucket.
    foreach (['eligible-unpaid', 'without-payment-request', 'leave-taken-unpaid'] as $kind) {
        [, $ids] = paymentCategoryTotal("/api/reports/payment/{$kind}", $h);
        expect($ids)->not->toContain($scene['paid']);
    }

    // e12/lt1 has no case and no approved lt1 leave: eligible without request.
    [$noReqTotal] = paymentCategoryTotal('/api/reports/payment/without-payment-request', $h);
    expect($noReqTotal)->toBe($cards['without_payment_request']['total']);
    $withoutRows = test()->getJson('/api/reports/payment/without-payment-request?per_page=100', $h)->assertOk()->json('data');
    $e12Lt1 = array_values(array_filter(
        $withoutRows,
        fn ($row) => ($row['employee']['id'] ?? null) === 'e12' && ($row['leave_type']['id'] ?? null) === 'lt1'
    ));
    expect($e12Lt1)->toHaveCount(1);
    expect($e12Lt1[0]['payment_case'])->toBeNull();
    expect($e12Lt1[0]['leave_request'])->toBeNull();

    // Case categories equal their endpoints row for row.
    $cardByKind = ['paid' => 'paid', 'pending-hr' => 'pending_hr', 'pending-accounting' => 'pending_accounting', 'processing' => 'processing', 'rejected' => 'rejected'];
    foreach ([
        'paid' => $scene['paid'],
        'pending-hr' => $scene['pending'],
        'pending-accounting' => [$scene['sent'], $scene['processing']],
        'processing' => $scene['processing'],
        'rejected' => $scene['rejected'],
    ] as $kind => $expected) {
        [$total, $ids] = paymentCategoryTotal("/api/reports/payment/{$kind}", $h);
        foreach ((array) $expected as $id) {
            expect($ids)->toContain($id);
        }
        expect($total)->toBe($cards[$cardByKind[$kind]]['total']);
    }

    // Money: read straight from the ledger of created cases.
    $eligibleSum = (float) LeavePaymentCase::query()->sum('eligible_amount');
    $paidSum = (float) LeavePaymentCase::query()->where('status', 'paid')->sum('calculated_amount');
    $approvedSum = (float) LeavePaymentCase::query()->where('status', 'hr_approved')->sum('calculated_amount');
    $outstandingSum = (float) LeavePaymentCase::query()->where('status', '!=', 'paid')->sum('eligible_amount');
    expect((float) $summary['amounts']['total_eligible_amount'])->toBe((float) $eligibleSum);
    expect((float) $summary['amounts']['total_paid_amount'])->toBe((float) $paidSum);
    expect((float) $summary['amounts']['total_approved_amount'])->toBe((float) $approvedSum);
    expect((float) $summary['amounts']['outstanding_amount'])->toBe((float) $outstandingSum);
    expect($paidSum)->toBe(400000.0);
    expect($approvedSum)->toBe(400000.0);
});

it('restricts Accounting to settlement statuses with redacted fields', function () {
    $scene = paymentScene();
    grantAccountingToE10();
    $h = payHeaders('u10');

    $summary = test()->getJson('/api/reports/payment/summary', $h)->assertOk()->json('data');
    expect($summary['cards']['paid']['total'])->toBe(1);
    expect($summary['cards']['pending_accounting']['total'])->toBe(2);

    // Buckets with no settlement-status member read empty for Accounting.
    foreach (['pending-hr', 'rejected', 'without-payment-request'] as $kind) {
        $res = test()->getJson("/api/reports/payment/{$kind}", $h)->assertOk()->json();
        expect($res['meta']['total'])->toBe(0);
        expect($res['data'])->toBe([]);
    }

    // Pair buckets are subset-aware too: only sent/processing members remain
    // (e6 sent, e11 processing) — never HR-pending, rejected or caseless pairs.
    expect(test()->getJson('/api/reports/payment/eligible-unpaid', $h)->json('meta.total'))->toBe(2);
    expect(test()->getJson('/api/reports/payment/leave-taken-unpaid', $h)->json('meta.total'))->toBe(1);
    expect(test()->getJson('/api/reports/payment/eligible-no-leave', $h)->json('meta.total'))->toBe(1);

    // Paid rows carry payable facts only.
    $res = test()->getJson('/api/reports/payment/paid', $h)->assertOk()->json();
    expect($res['meta']['total'])->toBe(1);
    $row = $res['data'][0];
    expect($row['id'])->toBe($scene['paid']);
    expect($row)->toHaveKeys(['id', 'payment_type', 'status', 'currency', 'eligible_amount', 'calculated_amount', 'employee', 'submitted_at']);
    expect($row)->not->toHaveKeys([
        'salary_basis', 'salary_amount', 'allowances_included', 'allowances_excluded',
        'calculation_snapshot', 'eligibility_snapshot',
        'rejection_reason', 'cancellation_reason', 'leave_type',
        'created_by', 'hr_reviewed_by', 'processed_by',
    ]);
    expect($row['employee']['full_name'])->not->toBeEmpty();
    expect($row['employee'])->not->toHaveKey('payroll_no');

    // Separation of duties holds through the report surface too: Accounting
    // holds none of the leave/HR-eligibility decision codes.
    $accountant = User::where('username', 'u10')->firstOrFail();
    $perms = app(PermissionService::class);
    expect($perms->can($accountant, 'requests', 'approve'))->toBeFalse();
    expect($perms->can($accountant, 'leave_payments', 'approve'))->toBeFalse();
    expect($perms->can($accountant, 'leave_payments', 'review'))->toBeFalse();
    test()->postJson("/api/leave-payments/{$scene['pending']}/approve", [], $h)->assertForbidden();

    // HR cannot mark paid.
    test()->postJson("/api/leave-payments/{$scene['processing']}/paid", [], payHeaders('u3'))->assertForbidden();
});

it('lets employees see only their own payment history', function () {
    $scene = paymentScene();

    // e11 owns exactly one case (processing).
    $mine = test()->getJson('/api/reports/payment/employees/e11/history', payHeaders('u11'))->assertOk()->json();
    expect($mine['meta']['total'])->toBe(1);
    expect($mine['data'][0]['id'])->toBe($scene['processing']);
    expect($mine['data'][0])->not->toHaveKeys(['salary_basis', 'calculation_snapshot', 'eligibility_snapshot']);

    // Someone else's history is closed to them.
    test()->getJson('/api/reports/payment/employees/e7/history', payHeaders('u11'))->assertForbidden();

    // HR sees the same history in full (snapshots included).
    $hrView = test()->getJson('/api/reports/payment/employees/e7/history', payHeaders('u3'))->assertOk()->json();
    expect($hrView['meta']['total'])->toBe(1);
    expect($hrView['data'][0])->toHaveKeys(['salary_basis', 'calculation_snapshot', 'eligibility_snapshot']);
});

it('audits payment report access and denies stale capabilities', function () {
    paymentScene();
    $h = payHeaders('u3');

    test()->getJson('/api/reports/payment/summary', $h)->assertOk();
    expect(
        AuditLog::where('entity_type', 'report')->where('entity_id', 'payment-overview')->where('action', 'accessed')->count()
    )->toBeGreaterThanOrEqual(1);
    $last = AuditLog::where('entity_type', 'report')->orderByDesc('id')->firstOrFail();
    expect($last->actor_user_id)->toBe('u3');

    // Revoke the Accounting role mid-session: the same token must now fail
    // (u10 keeps only SELF_SERVICE, which opens neither gate).
    $before = test()->getJson('/api/reports/payment/summary', payHeaders('u10'))->assertOk();
    expect($before->json('meta.audience'))->toBe('accounting');

    test()->deleteJson('/api/org/employees/e10/roles/ACCOUNTING', [], payHeaders('u1'))->assertOk();

    // Service-level report denials render as 422 with an authorization error
    // (the assert* convention across ReportAuthorizationService).
    test()->getJson('/api/reports/payment/summary', payHeaders('u10'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('authorization');
});

it('enforces pagination caps, sort whitelists and filter echo on payment endpoints', function () {
    paymentScene();
    $h = payHeaders('u3');

    $res = test()->getJson('/api/reports/payment/paid?per_page=500', $h)->assertOk()->json();
    expect($res['meta']['per_page'])->toBe(200);

    test()->getJson('/api/reports/payment/paid?per_page=0', $h)->assertStatus(422);

    // Unknown sort falls back instead of erroring or injecting ORDER BY.
    test()->getJson('/api/reports/payment/paid?sort=password', $h)->assertOk();

    $asc = test()->getJson('/api/reports/payment/rejected?sort=calculated_amount&direction=asc&per_page=100', $h)->assertOk()->json();
    $amounts = array_column($asc['data'], 'calculated_amount');
    $sorted = $amounts;
    sort($sorted);
    expect($amounts)->toBe($sorted);

    // Date windows and links behave.
    $empty = test()->getJson('/api/reports/payment/paid?from=2020-01-01&to=2020-12-31', $h)->assertOk()->json();
    expect($empty['meta']['total'])->toBe(0);
    expect($empty['meta']['filters']['from'])->toBe('2020-01-01');

    $paged = test()->getJson('/api/reports/payment/eligible-unpaid?payment_type=leave_encashment&per_page=1&page=1', $h)->assertOk()->json();
    expect($paged['links']['next'])->not->toBeNull();
    expect($paged['links']['first'])->toContain('payment_type=leave_encashment');
    expect($paged['meta']['filters']['payment_type'] ?? null)->toBe('leave_encashment');
});

it('keeps exports scoped to the active tab and filters', function () {
    $scene = paymentScene();
    $h = payHeaders('u3');

    $export = test()->postJson('/api/reports/exports', [
        'report_type' => 'payments',
        'format' => 'csv',
        'filters' => ['status' => 'paid'],
    ], $h)->assertStatus(201)->json('data');

    expect($export['filters']['status'] ?? null)->toBe('paid');
    expect($export['scope_snapshot'] ?? null)->not->toBeNull();
    expect($export['row_count'])->toBe(1);
});
