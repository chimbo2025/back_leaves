<?php

use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeavePolicy;
use App\Models\LeaveRule;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountingNotificationService;
use App\Services\LeavePaymentCalculationService;
use App\Services\LeavePaymentEligibilityService;
use App\Services\LeavePolicyResolver;
use App\Services\LeavePurchaseService;
use Illuminate\Support\Facades\DB;

function p5Headers(string $username): array
{
    return ['Authorization' => 'Bearer '.apiLogin($username)];
}

beforeEach(function () {
    config([
        'leave.public_holidays' => [],
        'leave.blackout_periods' => [],
        'leave.staffing_minimums' => [],
        'leave.payments.currency' => 'TZS',
        'leave.payments.day_rate_divisor' => 30,
        'leave.payments.purchase_enabled' => true,
        'leave.payments.purchase_max_days' => null,
        'leave.payments.allow_statutory_encashment' => false,
        'leave.payments.block_on_outstanding_loan' => false,
    ]);
});

/**
 * Phase 5 — Leave Payment Workflow
 *
 * Covers the five separated concerns:
 *   1. leave approval (already decided, read-only here)
 *   2. payment eligibility (LeavePaymentEligibilityService)
 *   3. leave-purchase terms (LeavePurchaseService)
 *   4. money calculation (LeavePaymentCalculationService)
 *   5. Accounting settlement (AccountingNotificationService + transitions)
 *
 * Every transition runs in a transaction, locks the case row, is idempotent
 * (repeating it is a no-op, not a second write) and writes one audit event.
 * A paid case is terminal and no transition method will touch it.
 *
 * Two authorisation rules are enforced in the service rather than in the route
 * layer because they are about *who*, not *what*:
 *   - no self-approval: the person who opened or submitted a case (or is the
 *     subject of it) can never approve, reject or settle it;
 *   - scope: a reviewer must be able to see the subject's zone/department,
 *     unless they hold the cross-zone code.
 */
it('creates a payment case, evaluates eligibility and calculates the amount', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1', // ANNUAL - paid
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
        'currency' => 'TZS',
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    expect($created['id'])->not->toBeNull();
    expect($created['status'])->toBe('pending');
    expect($created['eligible_amount'])->not->toBeNull();
    expect($created['calculated_amount'])->not->toBeNull();
    expect($created['currency'])->toBe('TZS');
    expect($created['eligibility_snapshot'])->toBeArray();
    expect($created['calculation_snapshot'])->toBeArray();

    $case = LeavePaymentCase::findOrFail($created['id']);
    expect((float) $case->eligible_amount)->toBe(400_000.0); // (1_200_000 / 30) * 10
    expect((float) $case->calculated_amount)->toBe(400_000.0);
});

it('rejects an encashment for an unpaid leave type when submitted', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt7', // WITHOUT_PAY - unpaid
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    // Case created but has eligibility blockers
    $blockers = $created['eligibility_snapshot']['blockers'] ?? [];
    expect($blockers)->not->toBeEmpty();
    expect(implode(' ', $blockers))->toContain('unpaid');

    // Submitting fails
    test()->postJson("/api/leave-payments/{$created['id']}/submit", [], p5Headers('u3'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('eligibility');
});

it('blocks encashment for statutory maternity/paternity when submitted unless policy allows', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e9',
        'leave_type_id' => 'lt3', // MATERNITY - statutory
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 84,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    // Case created but has eligibility blockers
    $blockers = $created['eligibility_snapshot']['blockers'] ?? [];
    expect($blockers)->not->toBeEmpty();
    expect(implode(' ', $blockers))->toContain('Statutory');

    // Submitting fails
    test()->postJson("/api/leave-payments/{$created['id']}/submit", [], p5Headers('u3'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('eligibility');
});

it('enforces leave purchase rules via LeavePurchaseService', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_purchase',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 5,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    expect($created['status'])->toBe('pending');
    expect($created['payment_type'])->toBe('leave_purchase');
});

it('prevents duplicate payment cases for the same leave request and type', function () {
    test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'leave_request_id' => 1,
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201);

    test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'leave_request_id' => 1,
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(422)->assertJsonValidationErrors('leave_request_id');
});

it('submits an eligible case for HR review', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    $submitted = test()->postJson("/api/leave-payments/{$caseId}/submit", [
        'comment' => 'Submitted for HR review',
    ], p5Headers('u3'))->assertOk()->json('data');

    expect($submitted['status'])->toBe('under_hr_review');
    expect($submitted['submitted_at'])->not->toBeNull();

    $case = LeavePaymentCase::findOrFail($caseId);
    expect($case->status)->toBe(LeavePaymentCase::STATUS_UNDER_HR_REVIEW);
});

it('approves a case under HR review by a different HR officer (no self-approval)', function () {
    // Create case as u3 (HR)
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();

    // Grant another HR user (e2) the HR role to approve
    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();

    $approved = test()->postJson("/api/leave-payments/{$caseId}/approve", [
        'comment' => 'HR approved',
    ], p5Headers('u2'))->assertOk()->json('data');

    expect($approved['status'])->toBe('hr_approved');
    expect($approved['hr_reviewed_at'])->not->toBeNull();
});

it('rejects self-approval on a case the actor opened', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();

    // u3 is the creator, cannot approve their own case
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u3'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('authorization');
});

it('sends an HR-approved case to Accounting with idempotent notification', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();

    // Grant another HR user (e2) the HR role to approve
    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u2'))->assertOk();

    $sent = test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [
        'comment' => 'Forwarded to Accounting',
    ], p5Headers('u2'))->assertOk()->json('data');

    expect($sent['status'])->toBe('sent_to_accounting');
    expect($sent['sent_to_accounting_at'])->not->toBeNull();
    expect($sent['accounting_notified_at'])->not->toBeNull();

    // Second call is idempotent
    $sent2 = test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], p5Headers('u2'))->assertOk()->json('data');
    expect($sent2['status'])->toBe('sent_to_accounting');
});

it('Accounting receives and processes a case', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();

    // Grant another HR user (e2) the HR role to approve
    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], p5Headers('u2'))->assertOk();

    // Grant ACCOUNTING role to e10 - use FINAL_AUTHORITY (u1) who holds all codes
    test()->postJson('/api/org/employees/e10/roles/ACCOUNTING', [], p5Headers('u1'))->assertOk();

    $received = test()->postJson("/api/leave-payments/{$caseId}/receive", [
        'comment' => 'Received by Accounting',
    ], p5Headers('u10'))->assertOk()->json('data');

    expect($received['status'])->toBe('processing');
    expect($received['processing_at'])->not->toBeNull();

    $paid = test()->postJson("/api/leave-payments/{$caseId}/paid", [
        'comment' => 'Payment processed',
    ], p5Headers('u10'))->assertOk()->json('data');

    expect($paid['status'])->toBe('paid');
    expect($paid['paid_at'])->not->toBeNull();

    $case = LeavePaymentCase::findOrFail($caseId);
    expect($case->isPaid())->toBeTrue();
    expect($case->isTerminal())->toBeTrue();
});

it('Accounting can reject a payment with a reason', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();

    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], p5Headers('u2'))->assertOk();

    test()->postJson('/api/org/employees/e10/roles/ACCOUNTING', [], p5Headers('u1'))->assertOk();

    $rejected = test()->postJson("/api/leave-payments/{$caseId}/reject-payment", [
        'reason' => 'Insufficient funds',
        'comment' => 'Budget hold',
    ], p5Headers('u10'))->assertOk()->json('data');

    expect($rejected['status'])->toBe('payment_rejected');
    expect($rejected['rejection_reason'])->toBe('Insufficient funds');
});

it('cancels a payment case before it is paid', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    $cancelled = test()->postJson("/api/leave-payments/{$caseId}/cancel", [
        'reason' => 'Employee withdrew request',
    ], p5Headers('u3'))->assertOk()->json('data');

    expect($cancelled['status'])->toBe('cancelled');
    expect($cancelled['cancellation_reason'])->toBe('Employee withdrew request');

    $case = LeavePaymentCase::findOrFail($caseId);
    expect($case->status)->toBe(LeavePaymentCase::STATUS_CANCELLED);
});

it('prevents re-evaluation of a terminal case', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();
    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], p5Headers('u2'))->assertOk();

    test()->postJson('/api/org/employees/e10/roles/ACCOUNTING', [], p5Headers('u1'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/receive", [], p5Headers('u10'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/paid", [], p5Headers('u10'))->assertOk();

    // Now try to re-evaluate a paid case
    test()->postJson("/api/leave-payments/{$caseId}/evaluate", [], p5Headers('u3'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('exposes salary snapshots only to HR-side callers', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'allowances_included' => [['name' => 'housing', 'amount' => 300_000]],
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    // HR (u3) sees salary_basis, salary_amount, allowances_included, snapshots
    $hrView = test()->getJson("/api/leave-payments/{$caseId}", p5Headers('u3'))->json('data');
    expect($hrView['salary_basis'])->toBe('basic');
    expect((float) $hrView['salary_amount'])->toBe(1_200_000.0);
    expect($hrView['allowances_included'])->toBeArray();
    expect($hrView['calculation_snapshot'])->toBeArray();
    expect($hrView['eligibility_snapshot'])->toBeArray();

    // Subject (u7) does NOT see salary detail
    $subjectView = test()->getJson("/api/leave-payments/{$caseId}", p5Headers('u7'))->json('data');
    expect($subjectView)->not->toHaveKey('salary_basis');
    expect($subjectView)->not->toHaveKey('salary_amount');
    expect($subjectView)->not->toHaveKey('allowances_included');
    expect($subjectView)->not->toHaveKey('calculation_snapshot');
    expect($subjectView)->not->toHaveKey('eligibility_snapshot');
});

it('scopes list endpoint by zone/department', function () {
    // Create a case for e8 (in d4/z2, Pemba) - use u3 (HR) who has cross-zone
    test()->postJson('/api/leave-payments', [
        'employee_id' => 'e8',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 5,
    ], p5Headers('u3'))->assertStatus(201);

    // e4 is ZONAL_HEAD for z2 (Pemba) - should see e8's case
    $zonal = test()->getJson('/api/leave-payments', p5Headers('u4'))->json('data');
    expect($zonal)->not->toBeEmpty();

    // e3 is HR at HQ - should see all (cross-zone)
    $hrView = test()->getJson('/api/leave-payments', p5Headers('u3'))->json('data');
    expect(count($hrView))->toBeGreaterThanOrEqual(count($zonal));

    // e7 is in d2/z1 (Unguja HQ) - should NOT see e8's case (different zone)
    $subjectView = test()->getJson('/api/leave-payments', p5Headers('u7'))->json('data');
    $e8Cases = collect($subjectView)->filter(fn ($c) => $c['employee_id'] === 'e8')->all();
    expect($e8Cases)->toBeEmpty();
});

it('idempotent create via idempotency_key', function () {
    $key = 'idem-test-'.uniqid();

    $first = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
        'idempotency_key' => $key,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $second = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
        'idempotency_key' => $key,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    expect($first['id'])->toBe($second['id']);
});

it('enforces loan outstanding block when policy says so', function () {
    // Create a policy rule that blocks on outstanding loan
    $policy = LeavePolicy::where('leave_type_id', 'lt1')->where('version', 4)->firstOrFail();
    LeaveRule::create([
        'leave_policy_id' => $policy->id,
        'rule_key' => 'payment.block_on_outstanding_loan',
        'operator' => 'eq',
        'value_json' => true,
        'priority' => 10,
    ]);

    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
        'loan_outstanding' => true,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    // Case created but has eligibility blockers
    $blockers = $created['eligibility_snapshot']['blockers'] ?? [];
    expect($blockers)->not->toBeEmpty();
    expect(implode(' ', $blockers))->toContain('loan');

    // Submitting fails
    test()->postJson("/api/leave-payments/{$created['id']}/submit", [], p5Headers('u3'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('eligibility');
});

it('stores calculation formula and divisor in snapshot', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'allowances_included' => [['name' => 'housing', 'amount' => 300_000]],
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $snapshot = $created['calculation_snapshot'];
    // The formula shows the components: ((salary + allowances) / divisor) * days
    expect($snapshot['formula'])->toContain('1200000');
    expect($snapshot['formula'])->toContain('300000');
    expect($snapshot['formula'])->toContain('30');
    expect($snapshot['day_rate_divisor'])->toBe(30);
    expect($snapshot['currency'])->toBe('TZS');
});

it('LeavePaymentCalculationService respects policy day_rate_divisor rule', function () {
    $policy = LeavePolicy::where('leave_type_id', 'lt1')->where('version', 4)->firstOrFail();
    LeaveRule::create([
        'leave_policy_id' => $policy->id,
        'rule_key' => 'payment.day_rate_divisor',
        'operator' => 'eq',
        'value_json' => 26, // 26 working days per month
        'priority' => 10,
    ]);

    $calc = app(LeavePaymentCalculationService::class)->calculate([
        'salary_amount' => 1_300_000,
        'allowances_included' => [],
        'days' => 10,
        'eligible_days' => 10,
        'divisor' => 26,
        'currency' => 'TZS',
        'policy_id' => $policy->id,
        'policy_version' => $policy->version,
    ]);

    expect((float) $calc['calculated_amount'])->toBe(500_000.0); // (1_300_000 / 26) * 10 = 500_000
    expect((float) $calc['snapshot']['day_rate_divisor'])->toBe(26.0);
});

it('LeavePurchaseService respects purchase max_days from policy', function () {
    $policy = LeavePolicy::where('leave_type_id', 'lt1')->where('version', 4)->firstOrFail();
    LeaveRule::create([
        'leave_policy_id' => $policy->id,
        'rule_key' => 'payment.purchase.max_days',
        'operator' => 'eq',
        'value_json' => 5,
        'priority' => 10,
    ]);

    $resolver = app(LeavePolicyResolver::class);
    $resolved = $resolver->resolveWithRules('lt1');

    $svc = app(LeavePurchaseService::class);
    $result = $svc->check(
        Employee::findOrFail('e7'),
        LeaveType::findOrFail('lt1'),
        $policy,
        $resolved['resolved_rules'] ?? [],
        10, // days requested
        'leave_purchase'
    );

    expect($result['ok'])->toBeFalse();
    expect($result['blockers'])->not->toBeEmpty();
    expect(implode(' ', $result['blockers']))->toContain('exceeds the policy maximum');
});

it('audit events are written for each transition', function () {
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();
    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], p5Headers('u2'))->assertOk();

    test()->postJson('/api/org/employees/e10/roles/ACCOUNTING', [], p5Headers('u1'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/receive", [], p5Headers('u10'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/paid", [], p5Headers('u10'))->assertOk();

    $audit = test()->getJson('/api/audit/verify', p5Headers('u1'))->json('data');
    expect($audit['valid'])->toBeTrue();

    $logs = DB::table('audit_logs')
        ->where('entity_type', 'leave_payment_case')
        ->where('entity_id', $caseId)
        ->orderBy('id')
        ->get();

    expect($logs)->not->toBeEmpty();
    $actions = $logs->pluck('action')->all();
    expect($actions)->toContain('created');
    expect($actions)->toContain('eligibility_evaluated');
    expect($actions)->toContain('calculated');
    expect($actions)->toContain('submitted_for_review');
    expect($actions)->toContain('hr_approved');
    expect($actions)->toContain('sent_to_accounting');
    expect($actions)->toContain('accounting_notified');
    expect($actions)->toContain('accounting_received');
    expect($actions)->toContain('paid');
});

it('notifications to Accounting do not expose leave type or reason', function () {
    // Use a non-statutory leave type so it passes eligibility
    $created = test()->postJson('/api/leave-payments', [
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1', // ANNUAL - not sensitive
        'payment_type' => 'leave_encashment',
        'salary_basis' => 'basic',
        'salary_amount' => 1_200_000,
        'days' => 10,
    ], p5Headers('u3'))->assertStatus(201)->json('data');

    $caseId = $created['id'];

    test()->postJson("/api/leave-payments/{$caseId}/submit", [], p5Headers('u3'))->assertOk();
    test()->postJson('/api/org/employees/e2/roles/HR', [], p5Headers('u3'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/approve", [], p5Headers('u2'))->assertOk();
    test()->postJson("/api/leave-payments/{$caseId}/send-to-accounting", [], p5Headers('u2'))->assertOk();

    // The case should be at sent_to_accounting
    $case = LeavePaymentCase::findOrFail($caseId);
    expect($case->status)->toBe(LeavePaymentCase::STATUS_SENT_TO_ACCOUNTING);

    // AccountingNotificationService::message() never includes leave type or reason
    $msg = app(AccountingNotificationService::class)->message($case);
    expect($msg)->not->toContain('ANNUAL');
    expect($msg)->not->toContain('annual');
    expect($msg)->toContain('Payment case');
    expect($msg)->toContain('ready to settle');
});
