# Phase 3 — Return-to-Work & Late-Return Handling Report

Status: implemented and green. Full suite: **170 passed (2069 assertions)**.

Scope note: this report covers only Phase 3 (return-to-work and late-return
handling). No Phase 4+ scope (notifications, payment workflow, digital
signatures, official letters, reports) was touched. No new `leave_types` were
created and `leave_balances` remains deliberately non-authoritative.

## What was built

### Table — `leave_returns`

`database/migrations/2026_10_11_090200_create_leave_returns_table.php`

- `id`, `leave_request_id` (unique FK → `leave_requests`, cascade),
  `employee_id` (FK → `employees`, cascade)
- `expected_return_date`, `actual_return_date` (nullable)
- `status`
- `reported_by` / `reported_at`, `reviewed_by` / `reviewed_at` (nullable, act as
  `decided_by` / `decided_at`), `decision_reason` (nullable)
- `explanation`, `explanation_status`, `supporting_document_id` (nullable FK →
  `leave_documents`)
- `late_days`, `excused_days`, `unauthorised_days`, `deducted_days` (default 0)
- `policy_snapshot` (JSON, nullable)
- `closed_by` (nullable FK → `employees`), `closed_at`, `closure_reason`
- timestamps
- Index on `(employee_id, status)`

Statuses: `pending`, `reported_on_time`, `late_pending_review`, `excused`,
`partially_excused`, `unauthorised`, `closed`.

## Lifecycle decision

Late-return review and case closure are **separate operations**.

- Review determines the outcome: `excused`, `partially_excused` or
  `unauthorised`.
- Close confirms that all required downstream actions are complete: the balance
  transaction posted where applicable, the audit event recorded, required
  documents verified, and the closure recorded.
- The review operation does **not** automatically close the case.
- The close endpoint is idempotent and rejects closure of an undecided case.
- Closed cases cannot be edited through normal endpoints; corrections require a
  controlled reopening or correction event.

State flow:

```text
pending → reported_on_time → closed
pending → late_pending_review → excused           → closed
pending → late_pending_review → partially_excused → closed
pending → late_pending_review → unauthorised      → closed
```

### Model — `app/Models/LeaveReturn.php`

Declares the `STATUS_*` constants and `STATUSES`, `REVIEWABLE =
['late_pending_review']`, `CLOSABLE = [reported_on_time, excused,
partially_excused, unauthorised]`, the fillable list, casts (`date:Y-m-d` for the
two dates, integers for the day counts, `array` for `policy_snapshot`) and the
`leaveRequest` / `employee` / `reportedBy` / `reviewedBy` / `supportingDocument`
relations.

### Services

- `app/Services/ExpectedReturnDateService.php` — `compute(LeaveRequest): ?array`
  returns the expected return date **and its snapshot**. `leave_policies` has no
  return-buffer column, so the rule (`METHOD = 'next_working_day'`) is: the day
  after `end_date`, advanced past weekends and every configured
  `config('leave.public_holidays')` date. The snapshot records `policy_id`,
  `policy_version`, `method`, `leave_end_date`, `public_holidays_considered` and
  `computed_at`, so a later policy or holiday change can never silently move an
  already-agreed date.
- `app/Services/ReturnToWorkService.php`
  - `openForRequest(LeaveRequest, ?string $actorUserId): ?LeaveReturn` — called on
    final approval. `firstOrCreate` on `leave_request_id` makes it idempotent;
    returns `null` for a request that is not approved or has no `end_date`. Audits
    `opened` once.
  - `reportReturn(User, LeaveReturn, array)` — owner-only; records
    `actual_return_date`, computes `late_days`; a late return **requires** an
    explanation and moves to `late_pending_review`, otherwise `reported_on_time`.
    Reporting the identical date again is a no-op. Audits `reported`.
  - `submitExplanation(User, LeaveReturn, string)` — owner-only, only while
    `late_pending_review`. Audits `explanation_submitted`.
- `app/Services/LateReturnService.php`
  - `review(User, LeaveReturn, array, ?string $comment)` — inside a
    `DB::transaction` with a `lockForUpdate` row lock: refuses self-review,
    confirms the case is `REVIEWABLE`, validates `0 <= excused_days <= late_days`,
    derives `unauthorised_days = late_days − excused_days` and `deducted_days =
    apply_deduction ? unauthorised_days : 0` **server-side** (client values are
    ignored), requires `leave_returns.excuse` when excusing and
    `leave_returns.apply_deduction` when deducting, sets the decided status,
    posts at most one idempotent ledger debit, and audits `reviewed`. Returns
    `{return, transaction}`.
  - `close(User, LeaveReturn, ?string $comment)` — a decision is sealed only
    when `canClose()` is satisfied. Inside a `DB::transaction` with a row lock it
    confirms the case is in a `CLOSABLE` status and, when `deducted_days > 0`,
    that the matching ledger row exists (found by `source_type = 'LeaveReturn'`,
    `source_id = leave_return_id`, `transaction_type = late_return_deduction`).
    It then records `closed_by` / `closed_at` / `closure_reason`, writes a
    `closed` audit event, and returns. It is **idempotent**: a second close
    returns the already-closed case without a second audit event or data change.
    A closed case is refused by report/explanation/review.
  - `canClose(LeaveReturn): ClosureResult` — returns
    `ClosureResult::invalid(...)` when the case is undecided or when a required
    deduction has not been posted; otherwise `ClosureResult::valid()`
    (`app/Support/ClosureResult.php`). No Phase 3 document is mandatory, so the
    document leg of the eligibility check is a no-op today; it is the obvious
    hook for a future `supporting_document_id` requirement.
  - `applyDeduction()` posts `transaction_type=late_return_deduction`,
    `direction=debit`, `source_type=LeaveReturn`, `source_id=leave_return_id`,
    `idempotency_key=return:{id}:late-deduction`. Reuses
    `BalanceTransactionService`, so a retried review can never deduct twice.

### Authorisation

- `app/Policies/LeaveReturnPolicy.php` — the owner controls their own report and
  explanation; `view_cross_zone` short-circuits to org-wide; otherwise a scoped
  read code plus `scopeCoversEmployee`. Review/close require their permission,
  exclude the owner, and are gated by `view`. The policy uses explicit literal
  `permissions->can()` calls so the integrity scanner can see every code.
- Routes (`routes/api.php`):
  - `POST /leave-requests/{leaveRequest}/return`
    (`permission:leave_returns.report_own`)
  - `GET  /leave-requests/{leaveRequest}/return`
  - `POST /leave-returns/{leaveReturn}/explanation`
    (`permission:leave_returns.report_own`)
  - `POST /leave-returns/{leaveReturn}/review`
    (`permission:leave_returns.review`)
  - `POST /leave-returns/{leaveReturn}/close`
    (`permission:leave_returns.close`)
- `app/Http/Controllers/Api/LeaveReturnController.php` — `report`, `show`,
  `explanation`, `review`, `close`. `report` lazily opens the case for an
  already-approved historical request (the service is idempotent).
- `app/Services/LeaveRequestService.php` — constructor now injects
  `ReturnToWorkService`; the final-approval branch calls `openForRequest(...)`
  after the return dockets are written.

### Permissions

`database/migrations/2026_10_11_090300_add_leave_returns_permissions.php` adds
the `leave_returns` module and **9 codes**:

| Code | SELF_SERVICE | DEPT_HEAD | ZONAL_HEAD | HR | FINAL_AUTHORITY | BREAK_GLASS |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `report_own` | ✓ | | | ✓ | ✓ | ✓ |
| `view_own` | ✓ | | | ✓ | ✓ | ✓ |
| `view_department` | | ✓ | | ✓ | ✓ | ✓ |
| `view_zone` | | | ✓ | ✓ | ✓ | ✓ |
| `view_cross_zone` | | | | ✓ | ✓ | ✓ |
| `review` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `excuse` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `apply_deduction` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `close` | | ✓ | ✓ | ✓ | ✓ | ✓ |

`view_cross_zone` is the one addition beyond the specification's eight codes: the
documented `AccessScopeService` invariant is that org-wide reach is granted only
by an explicit `<module>.view_cross_zone` code (Phase 2's `leave_plans` /
`leave_waivers` carry the same code for this reason). Without it a central role
could never see return cases outside its own departmental scope.

Catalog total is now **39 (through Phase 2) + 9 = 48**; the `modules` table is now
**10**; `PermissionService::SELF_SERVICE` now carries **11** codes.
`config/permission_enforcement.php`, the `DatabaseSeeder` matrix / authority
profile / `clean()`, and the count-based Phase 1 tests (`EffectiveGrantsTest`,
`RegistrationPermsTest`, `LifecycleRegressionTest`) were aligned.

## Semantics enforced

- The expected return date is computed at approval and frozen with its snapshot.
- A late report requires an explanation and is parked as `late_pending_review`;
  **nothing** touches a balance before a reviewer decides.
- `unauthorised_days` and `deducted_days` are always derived server-side; client
  values are ignored. Reviewers/admins can never inflate or erase a deduction
  from the request body.
- Exactly one `late_return_deduction` debit is posted per review; retries are
  idempotent on `return:{id}:late-deduction`.
- Dynamic usage is not double counted: the approved request's `used` is unchanged
  and `remaining` moves only by the ledger delta. `leave_balances` is never
  written.
- Reports, reviews, explanations, deductions and closures write audit events.
- Review and closure are separate: a review records the decision (`decision_reason`,
  `reviewed_by`, `reviewed_at`) and leaves the case closable; close is a distinct
  idempotent step that verifies the deduction landed and records the closure.
- A closed case is immutable through the API; corrections require a controlled
  reopening/correction event rather than an edit.
- The historical `leave_requests` row (status, end_date, days) is never rewritten
  by a return decision, and no duplicate request is created.

## Verification

- New `tests/Feature/Api/Phase3ReturnToWorkTest.php` — **18 tests / 125
  assertions**, covering the spec's 14 scenarios plus the closure lifecycle:
  correct expected return date; public-holiday skip; idempotent open (and no case
  for an unapproved request); on-time return with no deduction; late return
  requiring an explanation; owner reporting scope; explanation submission;
  reviewer department/zone scope; excused-days validation; server-derived
  unauthorised/deducted days; exactly one idempotent ledger transaction with no
  `leave_balances` drift; a retried report and retried review never duplicating;
  transactional rollback when the ledger write fails; historical request
  preservation; authorisation failures; closure records `closed_by` / `closed_at`
  / `closure_reason` and a single audit event; closure of an undecided case is
  refused; and a closed case is sealed against report/explanation/review.
- The Phase 3 services and hook were exercised through the real HTTP routes with
  seeded reviewers (`u6` DEPT_HEAD `d2`, `u4` ZONAL_HEAD `z2`) and the seeded
  approved request `r1` (`LVE-2026-0147`, e7, ends Fri 2026-10-16 → expected
  return Mon 2026-10-19).
- Full suite: **170 passed (2069 assertions)** (was 152 / 1788 before Phase 3).

## Unresolved policy decisions (flagged, not silently assumed)

1. **Deduction target leave type.** A late return is deducted from the *same*
   leave type as the underlying request (`$request->leave_type_id`). If policy
   should instead deduct from a dedicated type or from the next accrual, the
   `late_type_id` in `applyDeduction()` is the single change point.
2. **Return-buffer / grace period.** There is no policy field for a grace period,
   so "expected return" is strictly the next working day. Adding a
   `return_buffer_days` policy column would extend `ExpectedReturnDateService`.
3. **Reopening a closed case.** The brief says corrections must be a controlled
   reopening/correction event rather than an edit, but does not specify the
   endpoint. This is deliberately not implemented in Phase 3; the immutability of
   a closed case is enforced, and a future `leave_returns.reopen` code plus a
   correction ledger reversal would be the natural home for it.
