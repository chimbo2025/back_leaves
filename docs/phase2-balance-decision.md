# Phase 2 — Balance-Effect Decision Report

Status: implemented and green. Full suite: **152 passed (1788 assertions)**.

Scope note: this report covers only the Phase 2 balance-effect decision and the
associated waiver-review refactor. No Phase 3+ scope (late-return deductions,
notifications, payments, signatures, letters, reports) was touched, no new
`leave_types` were created, and `leave_balances` was deliberately left
non-authoritative.

## Decision

Option 3 — a separate, append-only `balance_transactions` ledger.

`PolicyService::balanceFor()` remains the dynamic source of truth for accrual and
usage. The ledger records **only** the approved waiver/deferral effects that
change entitlement (effects the dynamic calculation does not already represent),
and its net is layered on top of the computed balance.

Rejected alternatives:

- Writing to `leave_balances` — makes a denormalised table authoritative and
  risks drift against the dynamic calculation.
- Posting a `usage` transaction for approved leave requests — double counts,
  because approved requests are already summed dynamically.

## What was built

### Ledger table

`database/migrations/2026_10_11_090000_create_balance_transactions_table.php`

- `employee_id`, `leave_type_id`, `leave_cycle_start`, `leave_cycle_end`
- `transaction_type` (accrual, usage, carry_forward, expiry, waiver_deduction,
  forfeiture, restoration, manual_adjustment, late_return_deduction, correction)
- `amount` (positive only) + `direction` (`credit` / `debit`)
- `source_type`, `source_id`, `leave_request_id` (nullable), `leave_waiver_id`
  (nullable), `policy_id` (nullable)
- `reason_code`, `reason`, `metadata_json`
- `idempotency_key` (unique), `reversed_transaction_id` (self FK), `created_by`
  (FK → `users`, nullable)
- Indexes on `(employee_id, leave_type_id)` and `transaction_type`

### Model — `app/Models/BalanceTransaction.php`

Append-only and immutable. `booted()` throws `DomainException` on `updating` and
`deleting`, so a correction must be a new reversal row. Declares `TYPES`,
`DIRECTIONS`, and `DYNAMICALLY_REPRESENTED_TYPES = ['accrual', 'usage']`.

### Service — `app/Services/BalanceTransactionService.php`

- `record(array $attributes)` — validates type/direction/positive amount and
  `idempotency_key`, then `firstOrCreate` on the key so a retry cannot post a
  duplicate.
- `reverse(BalanceTransaction $original, string $reason, ?string $actorId)` —
  posts an opposite `correction` row referencing the original.
- `ledgerDelta(employeeId, leaveTypeId)` — sums credits/debits excluding
  `DYNAMICALLY_REPRESENTED_TYPES`, returning `{credits, debits, net}`.

### PolicyService integration

`app/Services/PolicyService.php`

- Constructor now injects `BalanceTransactionService`.
- `balanceFor()` adds `net = credits − debits` to `remaining` for both the
  statutory and the general branches, and exposes `ledgerCredits` /
  `ledgerDebits`. `accrued` and `used` are never mutated by the ledger.

### LeaveWaiverService

`app/Services/LeaveWaiverService.php`

- Constructor is now
  `(PolicyService $policy, AuditService $audit, BalanceTransactionService $ledger)`.
- `approve()` / `reject()` replace the old `decide()`. Approve runs inside a
  single `DB::transaction` with a `lockForUpdate` row lock, computes the
  `effect_json` decision snapshot, posts exactly one idempotent ledger
  transaction when required via `applyBalanceEffect()`, and writes the audit
  event. Approve returns `{waiver, effect, transaction}`.
- `BALANCE_EFFECT_ACTIONS = ['employee_declined', 'employee_requested_deferral',
  'employer_deferred']`. `rescheduled` posts nothing.
- `effectForApproval()` / `ledgerSpec()` map:
  `employee_declined → forfeiture/debit`, deferrals → `carry_forward/credit`.
- Idempotency key: `waiver:{id}:effect:{action}`; reversals use
  `reversal:{original.id}`.

## Semantics enforced

- Exactly one ledger transaction per approved effect; retries are idempotent.
- No ledger transaction for actions that do not change entitlement.
- Dynamic usage is not double counted: `used` is unchanged and `remaining` moves
  only by the ledger delta.
- `effect_json` is the policy decision snapshot; `balance_transactions` is the
  applied record; `audit_events` holds actor/timestamp/decision/reason.
- Posted rows are immutable; corrections are reversal transactions.
- Historical leave requests are never rewritten by a waiver decision.

## Waiver review refactor (per directive)

- New permission codes: `leave_waivers.view_own`, `reject`, `view_department`,
  `view_zone`, `view_cross_zone` (migration
  `2026_10_11_090100_add_leave_waiver_scope_permissions.php`). Catalog total is
  now **23 (Phase 1) + 8 (leave_plans) + 8 (leave_waivers) = 39**;
  `PermissionService::SELF_SERVICE` now carries 9 codes.
- Explicit `POST /leave-waivers/{id}/approve` and `/reject` (middleware
  `permission:leave_waivers.approve` / `.reject`) replace the generic `/decide`.
- `app/Policies/LeaveWaiverPolicy.php` is scope-aware: the owner controls their
  own waiver; otherwise `view_cross_zone` short-circuits, else a scoped read code
  plus `scopeCoversEmployee`. Decisions exclude the owner.
- `LeaveWaiverController::index` authorizes `viewAny` and scopes through
  `AccessScopeService::applyLeaveRequestScope(..., 'leave_waivers')`.
- `config/permission_enforcement.php` gained references for the five new codes;
  `DatabaseSeeder` matrix / authority profile / `clean()` were aligned, and
  `balance_transactions` was added to the truncate list.

## Verification

- New `tests/Feature/Api/Phase2BalanceLedgerTest.php` — 9 tests / 95 assertions:
  single credit; no-effect action; retry idempotency; debit-once without usage
  drift; transactional rollback when the ledger write fails; unauthorised
  refusal; immutability; reversal correction; snapshot placement.
- `Phase2LeavePlanningTest` updated to approve/reject with ledger assertions.
- Count-based Phase 1 tests updated (catalog 34→39, sources 8→9, SELF_SERVICE
  8→9) and stale comments refreshed.
- Full suite: **152 passed (1788 assertions)**.
