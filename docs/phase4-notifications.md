# Phase 4 — Leave Notifications & Reminders Report

Status: implemented and green. Full suite: **191 passed (2229 assertions)**.

Scope note: this report covers only Phase 4 (leave notifications and reminders).
No Phase 5+ scope (leave payments, digital signatures, official letters,
government logos, PDF generation, reports) was touched. No new `leave_types`
were created and `leave_balances` remains deliberately non-authoritative.

## Reuse, not replacement

Phase 4 deliberately **extends** the notification infrastructure that already
existed instead of standing up a second system:

- The existing custom `notifications` table and `NotificationService` are
  untouched and keep serving the assignment/handover notifications.
- Laravel's default `notifications` table (the `DatabaseNotification` schema)
  was **not** created. This project's `notifications` table has always had a
  different shape (`recipient_employee_id`, `event_type`, `is_privacy_safe`, …).
- `LeaveNotificationService` is the new layer that the Phase 4 domain events and
  reminder jobs speak to. It wraps `NotificationService::notify()` and adds
  channel selection, delivery tracking, queued email and idempotency.

## What was built

### Tables

`database/migrations/2026_10_11_100000_create_notification_deliveries_table.php`

- `notification_deliveries`: `id`, `notification_id` (FK → `notifications`,
  cascade), `channel` (`database` | `email`), `recipient` (employee id or email
  address), `status` (`queued` | `sent` | `failed` | `skipped`),
  `provider_message_id` (nullable), `sent_at`, `failed_at`, `failure_reason`,
  `attempts` (default 0), `idempotency_key` (unique), timestamps. Index on
  `(notification_id, channel)`.
- One delivery row per channel. The row is the unit of work: a retry updates the
  same row instead of minting a new one.

`database/migrations/2026_10_11_100100_create_notification_preferences_table.php`

- `notification_preferences`: `id`, `user_id` (FK → `users`, cascade),
  `event_type`, `email_enabled` (default true), `database_enabled` (default
  true), `language` (default `en`), `timing_config_json` (nullable JSON),
  timestamps. Unique on `(user_id, event_type)`.
- A row is a deliberate opt-out. Absence means defaults (both channels on,
  config timing).

`database/migrations/2026_10_11_100200_add_notifications_permissions.php`

- Adds the `notifications` module and three codes (idempotent, guards on role
  existence) — see Permissions below.

### Models

- `app/Models/NotificationDelivery.php` — `CHANNEL_*` / `STATUS_*` constants,
  casts, `notification()` relation and `isSent()`.
- `app/Models/NotificationPreference.php` — fillable + boolean/array casts.
- `app/Models/Notification.php` — added a `deliveries(): HasMany` relation.

### Configuration

`config/leave.php` → `notifications`:

| Key | Default | Meaning |
|---|---|---|
| `leave_start_days_before` | 2 | remind N days before an approved leave starts |
| `leave_end_days_before` | 1 | remind N days before an approved leave ends |
| `return_reminder_days_before` | 1 | remind N days before the expected return |
| `late_return_grace_days` | 0 | days past expected return before alerting |
| `low_balance_threshold` | 3.0 | remaining days at/below which to remind |
| `plan_selection_lead_days` | 7 | remind N days before a proposed plan expires |
| `email_retry_attempts` | 3 | queued email attempts before giving up |
| `email_retry_backoff` | `[60,300,900]` | seconds between attempts |

Every threshold is read from config. **No controller and no service hard-codes a
day count.** A per-user override lives in
`notification_preferences.timing_config_json` and is merged over these values by
`NotificationPreferenceService::timing()`.

### Domain events (13)

`app/Events/`: `LeaveRequestSubmitted`, `LeaveRequestApproved`,
`LeaveRequestRejected`, `LeaveRequestReturned`, `LeaveStartingSoon`,
`LeaveEndingSoon`, `ReturnReminderDue`, `LateReturnDetected`,
`LeaveBalanceReminderDue`, `LeaveWaiverDecided`, `LeavePlanReadyForSelection`,
`LeavePlanConfirmed`, `LeavePlanExpired`.

`app/Listeners/LeaveNotificationSubscriber.php` maps each event to one
`LeaveNotificationService` method. It is registered in
`AppServiceProvider::boot()` via `Event::subscribe(...)` (there is no
`EventServiceProvider` in Laravel 12), so `php artisan event:list` also sees it.

### Services

- `app/Services/LeaveNotificationService.php` — the single gateway.
  - `send($recipientEmployeeId, $eventType, $message, $options)`:
    resolves the user's preference for the event type, writes the in-system row
    via `NotificationService::notify()`, and records one delivery row per
    enabled channel (database → `sent`/attempts 1; email → `queued`/attempts 0 +
    `SendNotificationEmail::dispatch`). If both channels are off for an optional
    type it produces nothing.
  - **Idempotency** applies only when `idempotencyKey` is passed (scheduled
    reminders). A second call with the same base key returns the existing
    notification without sending again. Real-time lifecycle events pass no key
    and are always delivered. Keys are suffixed `:database` / `:email`.
  - `idempotencyKey()` builds
    `employee:{id}:event:{event}:reference:{ref}:date:{date}`.
  - Domain helpers: `requestSubmitted|Approved|Rejected|Returned`,
    `leaveStartingSoon`, `leaveEndingSoon`, `returnReminder`,
    `lateReturnDetected`, `balanceReminder`, `waiverDecided`,
    `planReadyForSelection`, `planConfirmed`, `planExpired`.
  - **Privacy:** `lateReturnDetected()` sends the employee the full notice, but
    a manager receives the name and expected date only — the leave type and
    reason are appended **only** when the manager holds
    `notifications.view_sensitive` (`canSeeSensitive()`), and the row is marked
    `is_privacy_safe = !$sensitive` accordingly. Managers are resolved from
    `reporting_lines` (`employee_id → supervisor_employee_id`).
- `app/Services/NotificationPreferenceService.php` —
  `MANDATORY_EVENTS = [approved, rejected, late_return_detected,
  security_alert]`; `OPTIONAL_EVENTS` = the rest. `isMandatory()`,
  `emailEnabled()` / `databaseEnabled()` (short-circuit to `true` for mandatory),
  `language()`, `timing()`, `preferenceFor()`, `set()` and `forUser()` (the full
  effective view with a `mandatory` flag per event type).
- `app/Services/LeaveReminderScheduler.php` — `run()` runs seven idempotent
  sweeps and returns per-sweep counts:
  `leaveCycleReminders`, `leaveStartReminders`, `leaveEndReminders`,
  `returnReminders`, `detectLateReturns`, `balanceReminders`,
  `unselectedPlanReminders`. Each derives its idempotency key from the reference
  (request/return/plan/employee id) and the reference **date** — not from
  "today" — so a re-run on the same day, or on every day of the lead window,
  delivers at most once per reference.

### Mail delivery

- `app/Support/NotificationMailer.php` (contract) +
  `app/Support/LaravelNotificationMailer.php` (default) —
  `send(NotificationDelivery): ?string`. The seam lets a test swap in a mailer
  that succeeds, fails, or returns a provider id without touching the transport.
  The default renders `app/Mail/LeaveNotificationMail.php` (an inline
  `Content(htmlString: nl2br(e($message)))`, so no Blade view is needed).
- `app/Jobs/SendNotificationEmail.php` — `ShouldQueue`.
  - A delivery already `sent` is a **no-op**, which makes the queue's
    at-least-once behaviour safe: running the job twice never sends twice.
  - Otherwise it increments `attempts`, sends, and on success records
    `provider_message_id` / `sent_at`. On failure it records `status=failed`,
    `failed_at`, `failure_reason` and **rethrows** so the queue retry policy
    still applies.
  - `tries` and `backoff()` come from `leave.notifications.email_retry_*`.
- `app/Jobs/RunLeaveReminders.php` — the queued daily entry point; it does no
  work itself and delegates to `LeaveReminderScheduler::run()`.

`routes/console.php` schedules `RunLeaveReminders` `dailyAt('07:00')` with
`withoutOverlapping()`.

### Event wiring

- `LeaveRequestService` dispatches `LeaveRequestSubmitted` (→ first assignee),
  `LeaveRequestApproved` (final approval), `LeaveRequestRejected` and
  `LeaveRequestReturned`. The pre-existing inline assignment notifications and
  the emergency SMS block are kept.
- `LeaveWaiverService::approve()` dispatches `LeaveWaiverDecided($waiver, true)`
  only when the decision actually lands as `approved` (an expired attempt is
  not an approval); `reject()` dispatches `LeaveWaiverDecided($waiver, false)`
  only when the status is `rejected`.
- `LeavePlanService` dispatches `LeavePlanReadyForSelection` on `propose()`,
  `LeavePlanConfirmed` on a `confirmed` decision, and `LeavePlanExpired` on the
  `cycleHasPassed` paths in `select()` and `confirm()`.

### API

- `app/Http/Controllers/Api/NotificationPreferenceController.php` — `index` and
  `update`.
- Routes (`routes/api.php`): the existing notification routes are now gated —
  `GET /notifications`, `GET /notifications/unread-count` and
  `POST /notifications/{id}/read` require `permission:notifications.view_own`;
  `GET`/`PATCH /notifications/preferences` require
  `permission:notifications.manage_preferences`.
- The preference `update` refuses an unknown event type (422) and, for a
  mandatory event, returns the effective view with `mandatory: true` and the
  channels still on — it stores no disabling row, so the UI shows a locked
  switch rather than a silent no-op opt-out.

### Permissions

`notifications` module with **3 codes** (deliberately not `notifications.view`):

| Code | SELF_SERVICE | DEPT_HEAD | ZONAL_HEAD | HR | FINAL_AUTHORITY | BREAK_GLASS |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `view_own` | ✓ | | | ✓ | ✓ | ✓ |
| `manage_preferences` | ✓ | | | ✓ | ✓ | ✓ |
| `view_sensitive` | | | | ✓ | ✓ | ✓ |

`view_sensitive` is the one addition beyond the specification's list. It is what
lets a late-return or medical-leave message carry detail to HR and the authority
roles while an ordinary line manager still receives only the privacy-safe shell.
Without an explicit code the "managers must not see medical detail" rule would
have to be hard-coded to role names.

Catalog total is now **48 (through Phase 3) + 3 = 51**; the `modules` table is
now **11**; `PermissionService::SELF_SERVICE` now carries **13** codes.
`config/permission_enforcement.php`, the `DatabaseSeeder` matrix / authority
profile / `clean()`, and the count-based tests (`EffectiveGrantsTest`,
`RegistrationPermsTest`, `LifecycleRegressionTest`) were aligned.

## Semantics enforced

- Notifications are **additive**: raising any notification never changes a leave
  request's status/dates/days and never moves a balance.
- A mandatory notification (approval, rejection, security, late-return) cannot
  be disabled by a preference row; the service ignores any stored opt-out for it.
- Email is delivered through a queued job that is idempotent per delivery row;
  failures are recorded and retried with a configurable backoff.
- Scheduled reminders are idempotent per reference + reference date.
- A manager without `notifications.view_sensitive` never receives the leave type
  or the reason of another employee's leave; the code-level rule is enforced in
  `LeaveNotificationService::lateReturnDetected()`.
- Sensitive detail is never placed in an SMS; `NotificationService`'s existing
  privacy-safe SMS template is unchanged.

## Verification

- New `tests/Feature/Api/Phase4NotificationTest.php` — **21 tests / 101
  assertions**, covering: per-channel delivery rows + queued email; queued email
  delivered once with a provider id; failed email recorded and rethrown;
  config-driven retry policy and mailer binding; optional opt-out silences a
  notification; mandatory opt-out ignored; preference API locks mandatory events,
  stores optional opt-outs and rejects unknown types; unauthenticated callers
  refused; scheduled-key idempotency; config-driven, idempotent leave-start
  sweep; a return reminder sent before the expected date and idempotent;
  late-return detection with privacy-safe manager notice; sensitive detail for a
  permitted manager; idempotent low-balance reminder; event wiring through the
  real request/plan/waiver services; a rejection notice carrying the reviewer
  reason and surviving an opt-out; non-mutation of request and balance; and the
  daily reminder job's delegation.
- Full suite: **191 passed (2229 assertions)** (was 170 / 2069 before Phase 4).

## Unresolved policy decisions (flagged, not silently assumed)

1. **Email transport in production.** The default mailer uses Laravel's `Mail`
   facade; the actual SMTP/provider credentials are a deployment concern. The
   `NotificationMailer` seam is the single place to swap in a provider SDK.
2. **SMS channel.** The schema and `NotificationService` support an `sms`
   channel, but Phase 4 delivers only `database` and `email`; no SMS delivery
   job is wired. Adding one means a second channel branch in
   `LeaveNotificationService::send()` and a matching delivery job.
3. **Cycle-expiry reminder.** `leaveCycleReminders()` uses the service-year end
   derived from `hire_date` as the cycle boundary. If carry-over expiry should
   follow a policy column instead, that single method is the change point.
4. **Localisation.** `language` is stored per preference but the composed
   messages are English-only today; a translation layer would consume
   `NotificationPreferenceService::language()`.
