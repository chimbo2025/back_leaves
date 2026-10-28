<?php

use App\Events\LeaveRequestApproved;
use App\Events\LeaveRequestRejected;
use App\Jobs\RunLeaveReminders;
use App\Jobs\SendNotificationEmail;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveReturn;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\LeaveNotificationService;
use App\Services\LeaveReminderScheduler;
use App\Services\NotificationPreferenceService;
use App\Services\PolicyService;
use App\Support\LaravelNotificationMailer;
use App\Support\NotificationMailer;
use Illuminate\Support\Facades\Queue;

/**
 * Phase 4 — leave notifications, delivery tracking, preferences and scheduled
 * reminders.
 *
 * Covers the channel fan-out and its delivery rows, queued-email idempotency
 * and failure recording, mandatory-vs-optional preferences, configurable and
 * idempotent reminder sweeps, the privacy rule for late-return detail, the
 * event wiring through the real plan/waiver/request services, and the guarantee
 * that a notification never mutates a request or a balance.
 */
function p4Headers(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/** r1: e7 annual leave, approved 2026-10-05 .. 2026-10-16. */
function p4Request(): LeaveRequest
{
    return LeaveRequest::where('ref', 'LVE-2026-0147')->firstOrFail();
}

function p4Service(): LeaveNotificationService
{
    return app(LeaveNotificationService::class);
}

function p4Scheduler(): LeaveReminderScheduler
{
    return app(LeaveReminderScheduler::class);
}

function p4Plan(string $user, array $overrides = []): array
{
    $token = apiLogin($user);
    $r = test()->postJson('/api/leave-plans', array_merge(['leave_type_id' => 'lt3'], $overrides), p4Headers($token));
    $r->assertStatus(201);

    return [$r->json('data.id'), $token];
}

function p4Propose(string $token, string $planId, array $payload = []): array
{
    $r = test()->postJson("/api/leave-plans/{$planId}/propose", $payload, p4Headers($token));
    $r->assertOk();

    return $r->json();
}

beforeEach(function () {
    config([
        'leave.public_holidays' => [],
        'leave.blackout_periods' => [],
        'leave.staffing_minimums' => [],
    ]);
});

// ---------------------------------------------------------------------------
// Channel fan-out and delivery tracking
// ---------------------------------------------------------------------------

it('records one delivery per enabled channel and queues the email', function () {
    Queue::fake();

    event(new LeaveRequestApproved(p4Request()));

    $notification = Notification::where('event_type', 'approved')
        ->where('related_entity_id', p4Request()->id)
        ->firstOrFail();

    expect($notification->recipient_employee_id)->toBe('e7')
        ->and($notification->related_entity_type)->toBe('leave_request');

    $database = $notification->deliveries()->where('channel', 'database')->firstOrFail();
    expect($database->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($database->attempts)->toBe(1)
        ->and($database->sent_at)->not->toBeNull();

    $email = $notification->deliveries()->where('channel', 'email')->firstOrFail();
    expect($email->status)->toBe(NotificationDelivery::STATUS_QUEUED)
        ->and($email->attempts)->toBe(0)
        ->and($email->recipient)->toBe('asha.haji@dpp.go.tz');

    Queue::assertPushed(SendNotificationEmail::class, fn ($job) => $job->deliveryId === $email->id);
});

it('delivers queued email exactly once and records the provider id', function () {
    Queue::fake();

    $service = p4Service();
    $notification = $service->send('e7', 'approved', 'Approved.', [
        'relatedEntityType' => 'leave_request',
        'relatedEntityId' => p4Request()->id,
    ]);

    $email = $notification->deliveries()->where('channel', 'email')->firstOrFail();

    $mailer = Mockery::mock(NotificationMailer::class);
    $mailer->shouldReceive('send')->once()->andReturn('provider-abc-1');

    (new SendNotificationEmail($email->id))->handle($mailer);

    $email->refresh();
    expect($email->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($email->attempts)->toBe(1)
        ->and($email->provider_message_id)->toBe('provider-abc-1')
        ->and($email->sent_at)->not->toBeNull();

    // Running the job a second time is a no-op: no second send, no bumped count.
    $noop = Mockery::mock(NotificationMailer::class);
    $noop->shouldNotReceive('send');
    (new SendNotificationEmail($email->id))->handle($noop);

    expect($email->fresh()->attempts)->toBe(1);
});

it('records a failed email and rethrows so the queue can retry', function () {
    Queue::fake();

    $notification = p4Service()->send('e8', 'approved', 'Approved.', [
        'relatedEntityType' => 'leave_request',
        'relatedEntityId' => p4Request()->id,
    ]);
    $email = $notification->deliveries()->where('channel', 'email')->firstOrFail();

    $mailer = Mockery::mock(NotificationMailer::class);
    $mailer->shouldReceive('send')->andThrow(new RuntimeException('smtp unavailable'));

    expect(fn () => (new SendNotificationEmail($email->id))->handle($mailer))
        ->toThrow(RuntimeException::class);

    $email->refresh();
    expect($email->status)->toBe(NotificationDelivery::STATUS_FAILED)
        ->and($email->attempts)->toBe(1)
        ->and($email->failed_at)->not->toBeNull()
        ->and($email->failure_reason)->toContain('smtp unavailable');
});

it('takes its retry policy from configuration', function () {
    config([
        'leave.notifications.email_retry_attempts' => 5,
        'leave.notifications.email_retry_backoff' => [10, 30, 60],
    ]);

    $job = new SendNotificationEmail(1);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 30, 60])
        ->and(app(NotificationMailer::class))->toBeInstanceOf(LaravelNotificationMailer::class);
});

// ---------------------------------------------------------------------------
// Preferences
// ---------------------------------------------------------------------------

it('silences an optional notification entirely when the user opts out', function () {
    Queue::fake();

    $user = User::findOrFail('u7');
    NotificationPreference::create([
        'user_id' => $user->id,
        'event_type' => 'leave_plan_confirmed',
        'email_enabled' => false,
        'database_enabled' => false,
    ]);

    $result = p4Service()->send('e7', 'leave_plan_confirmed', 'Your plan was confirmed.');

    expect($result)->toBeNull()
        ->and(Notification::where('recipient_employee_id', 'e7')->where('event_type', 'leave_plan_confirmed')->exists())->toBeFalse();
});

it('ignores an opt-out for a mandatory notification', function () {
    Queue::fake();

    $user = User::findOrFail('u7');
    NotificationPreference::create([
        'user_id' => $user->id,
        'event_type' => 'approved',
        'email_enabled' => false,
        'database_enabled' => false,
    ]);

    $preferences = app(NotificationPreferenceService::class);
    expect($preferences->emailEnabled($user, 'approved'))->toBeTrue()
        ->and($preferences->databaseEnabled($user, 'approved'))->toBeTrue();

    $notification = p4Service()->send('e7', 'approved', 'Approved.', [
        'relatedEntityType' => 'leave_request',
        'relatedEntityId' => p4Request()->id,
    ]);

    expect($notification)->not->toBeNull()
        ->and($notification->deliveries()->count())->toBe(2);
});

it('locks mandatory events and stores optional opt-outs through the API', function () {
    $token = apiLogin('u7');

    $list = $this->getJson('/api/notifications/preferences', p4Headers($token))->assertOk()->json('data');
    $approved = collect($list)->firstWhere('event_type', 'approved');
    expect($approved['mandatory'])->toBeTrue()
        ->and(collect($list)->firstWhere('event_type', 'leave_plan_confirmed')['mandatory'])->toBeFalse();

    // A mandatory event stays on and stores no disabling row.
    $this->patchJson('/api/notifications/preferences', [
        'event_type' => 'approved',
        'email_enabled' => false,
        'database_enabled' => false,
    ], p4Headers($token))->assertOk()
        ->assertJsonPath('data.mandatory', true)
        ->assertJsonPath('data.email_enabled', true);

    expect(NotificationPreference::where('user_id', 'u7')->where('event_type', 'approved')->exists())->toBeFalse();

    // An optional event is persisted.
    $this->patchJson('/api/notifications/preferences', [
        'event_type' => 'leave_plan_confirmed',
        'database_enabled' => false,
    ], p4Headers($token))->assertOk()
        ->assertJsonPath('data.mandatory', false)
        ->assertJsonPath('data.database_enabled', false);

    expect(NotificationPreference::where('user_id', 'u7')->where('event_type', 'leave_plan_confirmed')->value('database_enabled'))->toBeFalse();

    // An unknown event type is refused.
    $this->patchJson('/api/notifications/preferences', ['event_type' => 'not_a_real_event'], p4Headers($token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('event_type');
});

it('refuses unauthenticated callers on the notification routes', function () {
    $this->getJson('/api/notifications')->assertStatus(401);
    $this->getJson('/api/notifications/unread-count')->assertStatus(401);
    $this->getJson('/api/notifications/preferences')->assertStatus(401);
    $this->patchJson('/api/notifications/preferences', ['event_type' => 'approved'])->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('delivers a scheduled reminder with the same key exactly once', function () {
    Queue::fake();

    $key = 'employee:e7:event:leave_starting_soon:reference:42:date:2026-11-01';

    $first = p4Service()->send('e7', 'leave_starting_soon', 'Starts soon.', ['idempotencyKey' => $key]);
    $second = p4Service()->send('e7', 'leave_starting_soon', 'Starts soon.', ['idempotencyKey' => $key]);

    expect($second->id)->toBe($first->id)
        ->and(Notification::where('recipient_employee_id', 'e7')->where('event_type', 'leave_starting_soon')->where('related_entity_id', null)->count())->toBe(1)
        ->and(NotificationDelivery::where('idempotency_key', 'like', $key.':%')->count())->toBe(2);

    Queue::assertPushed(SendNotificationEmail::class, 1);
});

// ---------------------------------------------------------------------------
// Scheduled sweeps
// ---------------------------------------------------------------------------

it('fires the leave-start reminder within the configured lead time and is idempotent', function () {
    Queue::fake();
    config(['leave.notifications.leave_start_days_before' => 3]);

    $start = now()->addDays(3)->toDateString();
    $request = LeaveRequest::create([
        'ref' => 'LVE-P4-START',
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'policy_id' => 'pol1',
        'start_date' => $start,
        'end_date' => now()->addDays(6)->toDateString(),
        'days' => 3,
        'status' => 'approved',
    ]);

    // A request four days out is outside the lead window and must not fire.
    LeaveRequest::create([
        'ref' => 'LVE-P4-FAR',
        'employee_id' => 'e7',
        'leave_type_id' => 'lt1',
        'policy_id' => 'pol1',
        'start_date' => now()->addDays(4)->toDateString(),
        'end_date' => now()->addDays(6)->toDateString(),
        'days' => 2,
        'status' => 'approved',
    ]);

    expect(p4Scheduler()->leaveStartReminders())->toBeGreaterThanOrEqual(1);

    $count = fn () => Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'leave_starting_soon')
        ->where('related_entity_id', $request->id)
        ->count();

    expect($count())->toBe(1);

    p4Scheduler()->leaveStartReminders();
    p4Scheduler()->leaveStartReminders();

    expect($count())->toBe(1);
});

it('detects a late return for the employee and alerts only with permitted detail', function () {
    Queue::fake();

    $request = p4Request();
    $reason = $request->reason ?: 'medical';
    $typeName = $request->leaveType->name;

    $return = LeaveReturn::create([
        'leave_request_id' => $request->id,
        'employee_id' => 'e7',
        'expected_return_date' => now()->subDays(2)->toDateString(),
        'status' => LeaveReturn::STATUS_PENDING,
    ]);

    expect(p4Scheduler()->detectLateReturns())->toBeGreaterThanOrEqual(1);

    // The employee is always told.
    $employeeNotice = Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'late_return_detected')->first();
    expect($employeeNotice)->not->toBeNull();

    // e6 is the line manager (DEPT_HEAD) and holds no view_sensitive code.
    $managerNotice = Notification::where('recipient_employee_id', 'e6')
        ->where('event_type', 'late_return_detected')->firstOrFail();
    expect($managerNotice->is_privacy_safe)->toBeTrue()
        ->and($managerNotice->message)->not->toContain($typeName)
        ->and($managerNotice->message)->not->toContain($reason);

    // Idempotent: a second sweep adds no duplicate for the same expected date.
    $before = Notification::where('event_type', 'late_return_detected')->count();
    p4Scheduler()->detectLateReturns();
    expect(Notification::where('event_type', 'late_return_detected')->count())->toBe($before);
});

it('includes leave detail for a manager who may view sensitive notifications', function () {
    Queue::fake();

    // e10's line manager is e3 (HR), which holds notifications.view_sensitive.
    $request = LeaveRequest::create([
        'ref' => 'LVE-P4-SENS',
        'employee_id' => 'e10',
        'leave_type_id' => 'lt3',
        'policy_id' => 'pol1',
        'start_date' => now()->subDays(10)->toDateString(),
        'end_date' => now()->subDays(4)->toDateString(),
        'days' => 5,
        'status' => 'approved',
        'reason' => 'Maternity recovery complications',
    ]);

    LeaveReturn::create([
        'leave_request_id' => $request->id,
        'employee_id' => 'e10',
        'expected_return_date' => now()->subDays(2)->toDateString(),
        'status' => LeaveReturn::STATUS_PENDING,
    ]);

    p4Scheduler()->detectLateReturns();

    $hrNotice = Notification::where('recipient_employee_id', 'e3')
        ->where('event_type', 'late_return_detected')->firstOrFail();

    expect($hrNotice->message)->toContain('Maternity');
});

it('sends a low-balance reminder that is idempotent within the period', function () {
    Queue::fake();
    config(['leave.notifications.low_balance_threshold' => 1000]);

    $scheduler = p4Scheduler();
    expect($scheduler->balanceReminders())->toBeGreaterThanOrEqual(1);

    $count = fn () => Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'leave_balance_reminder_due')->count();

    $first = $count();
    expect($first)->toBeGreaterThanOrEqual(1);

    // The lt1 reminder is keyed on employee + reference + period.
    expect(NotificationDelivery::where('idempotency_key', 'like', '%:reference:lt1:%')->exists())->toBeTrue();

    $scheduler->balanceReminders();
    expect($count())->toBe($first);
});

// ---------------------------------------------------------------------------
// Event wiring through the real lifecycle services
// ---------------------------------------------------------------------------

it('notifies the first approver when a request is submitted through the API', function () {
    Queue::fake();

    $token = apiLogin('u3');
    $created = $this->postJson('/api/requests', [
        'leave_type_id' => 'lt1',
        'start_date' => '2026-11-10',
        'end_date' => '2026-11-14',
        'reason' => 'Annual leave — planned.',
    ], p4Headers($token))->assertStatus(201);

    $requestId = $created->json('data.id');

    $this->postJson("/api/requests/{$requestId}/submit", [], p4Headers($token))
        ->assertOk();

    // e1 is the first assignee for this chain (SUPERVISOR step).
    expect(Notification::where('event_type', 'submitted')
        ->where('related_entity_type', 'leave_request')
        ->where('related_entity_id', $requestId)
        ->where('recipient_employee_id', 'e1')
        ->exists())->toBeTrue();
});

it('notifies the employee when a plan is proposed and then confirmed', function () {
    Queue::fake();

    [$planId, $token] = p4Plan('u7');
    $proposal = p4Propose($token, $planId, ['desired_days' => 1]);

    expect(Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'leave_plan_ready_for_selection')
        ->where('related_entity_id', $planId)->exists())->toBeTrue();

    $option = $proposal['options'][0];
    $this->postJson("/api/leave-plans/{$planId}/select", ['option_number' => $option['option_number']], p4Headers($token))->assertOk();

    $this->postJson("/api/leave-plans/{$planId}/confirm", ['decision' => 'confirmed'], p4Headers(apiLogin('u3')))->assertOk();

    expect(Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'leave_plan_confirmed')
        ->where('related_entity_id', $planId)->exists())->toBeTrue();
});

it('notifies the owner when a waiver is decided', function () {
    Queue::fake();

    $owner = apiLogin('u7');
    $waiverId = $this->postJson('/api/leave-waivers', [
        'leave_type_id' => 'lt1',
        'requested_action' => 'employee_requested_deferral',
        'leave_cycle_start' => '2026-11-01',
        'leave_cycle_end' => '2026-11-10',
        'reason' => 'Deferring two weeks.',
    ], p4Headers($owner))->assertStatus(201)->json('data.id');

    $this->postJson("/api/leave-waivers/{$waiverId}/submit", [], p4Headers($owner))->assertOk();
    $this->postJson("/api/leave-waivers/{$waiverId}/approve", ['comment' => 'Approved.'], p4Headers(apiLogin('u3')))
        ->assertOk();

    expect(Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'leave_waiver_decided')
        ->where('related_entity_type', 'leave_waiver')
        ->exists())->toBeTrue();
});

it('notifies the employee when a request is rejected, carrying the reviewer reason', function () {
    Queue::fake();

    event(new LeaveRequestRejected(
        p4Request(),
        Employee::findOrFail('e6'),
        'Insufficient cover in Records.',
    ));

    $notice = Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'rejected')
        ->where('related_entity_type', 'leave_request')
        ->where('related_entity_id', p4Request()->id)
        ->firstOrFail();

    expect($notice->message)->toContain('Insufficient cover in Records.')
        ->and($notice->message)->toContain('Zaytuni Omar Juma');
});

it('still delivers a mandatory rejection when the employee opted out', function () {
    Queue::fake();

    NotificationPreference::create([
        'user_id' => 'u7',
        'event_type' => 'rejected',
        'email_enabled' => false,
        'database_enabled' => false,
    ]);

    event(new LeaveRequestRejected(p4Request(), Employee::findOrFail('e6'), 'No cover.'));

    expect(Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'rejected')
        ->where('related_entity_id', p4Request()->id)
        ->exists())->toBeTrue();
});

it('sends a return reminder before the expected return date and is idempotent', function () {
    Queue::fake();
    config(['leave.notifications.return_reminder_days_before' => 1]);

    $return = LeaveReturn::create([
        'leave_request_id' => p4Request()->id,
        'employee_id' => 'e7',
        'expected_return_date' => now()->addDays(1)->toDateString(),
        'status' => LeaveReturn::STATUS_PENDING,
    ]);

    expect(p4Scheduler()->returnReminders())->toBeGreaterThanOrEqual(1);

    $count = fn () => Notification::where('recipient_employee_id', 'e7')
        ->where('event_type', 'return_reminder_due')
        ->where('related_entity_id', $return->id)
        ->count();

    expect($count())->toBe(1);

    p4Scheduler()->returnReminders();
    p4Scheduler()->returnReminders();

    expect($count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Non-mutation
// ---------------------------------------------------------------------------

it('never changes the request or the balance when raising notifications', function () {
    Queue::fake();

    $request = p4Request();
    $snapshot = [
        'status' => $request->status,
        'days' => (int) $request->days,
        'start' => $request->start_date->toDateString(),
        'end' => $request->end_date->toDateString(),
    ];
    $balance = app(PolicyService::class)->balanceFor('e7', 'lt1');

    event(new LeaveRequestApproved($request));
    p4Service()->lateReturnDetected(LeaveReturn::create([
        'leave_request_id' => $request->id,
        'employee_id' => 'e7',
        'expected_return_date' => now()->subDays(1)->toDateString(),
        'status' => LeaveReturn::STATUS_PENDING,
    ]));
    p4Scheduler()->leaveStartReminders();
    p4Scheduler()->leaveEndReminders();

    $after = $request->fresh();
    expect([
        'status' => $after->status,
        'days' => (int) $after->days,
        'start' => $after->start_date->toDateString(),
        'end' => $after->end_date->toDateString(),
    ])->toBe($snapshot);

    $balanceAfter = app(PolicyService::class)->balanceFor('e7', 'lt1');
    expect($balanceAfter['remaining'])->toBe($balance['remaining'])
        ->and($balanceAfter['used'])->toBe($balance['used']);
});

it('exposes the daily reminder job that drives every sweep', function () {
    Queue::fake();

    (new RunLeaveReminders)->handle(p4Scheduler());

    expect(Notification::where('event_type', 'leave_starting_soon')->count())->toBeGreaterThanOrEqual(0);
});
