# Phase 5 — Leave Payment Workflow (Implementation Report)

**Date:** 2026-10-11  
**Status:** COMPLETE — all 213 tests passing (2627 assertions)  
**Scope:** Leave encashment and leave-purchase payment cases: eligibility, HR review, Accounting settlement.  
**Out of scope (Phase 6):** Digital signatures, official letters, PDF generation, QR verification, reports.

---

## 1. Database Changes

### New table: `leave_payment_cases`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `employee_id` | string | FK → employees |
| `leave_request_id` | bigint | nullable, FK → leave_requests |
| `leave_type_id` | string | FK → leave_types |
| `policy_id` | string | nullable, FK → leave_policies |
| `payment_type` | string | `leave_encashment` / `leave_purchase` |
| `status` | string | see Status Machine below |
| `salary_basis` | string | nullable, creator-supplied label |
| `salary_amount` | decimal(14,2) | nullable, creator-supplied amount |
| `allowances_included` | json | nullable, `[{name, amount}]` |
| `allowances_excluded` | json | nullable, `[{name, amount}]` |
| `eligible_amount` | decimal(14,2) | nullable |
| `calculated_amount` | decimal(14,2) | nullable |
| `currency` | char(3) | default `TZS` |
| `calculation_snapshot` | json | formula, divisor, day rate, inputs |
| `eligibility_snapshot` | json | all checks + blockers at evaluation time |
| `created_by` / `submitted_by` / `hr_reviewed_by` / `sent_to_accounting_by` / `processed_by` / `rejected_by` / `cancelled_by` | string | nullable, FK → employees |
| `submitted_at` / `hr_reviewed_at` / `sent_to_accounting_at` / `accounting_notified_at` / `processing_at` / `paid_at` / `rejected_at` / `cancelled_at` | timestamp | nullable |
| `rejection_reason` / `cancellation_reason` | text | nullable |
| `idempotency_key` | string | nullable, unique |
| `timestamps` | | created_at / updated_at |

**Constraints:**
- `unique(leave_request_id, payment_type)` — prevents duplicate charge for same leave
- `unique(idempotency_key)` — caller-supplied retry safety
- indexes on `(employee_id, status)` and `status`

### Permissions (migration `2026_10_11_110100`)
- Module: `leave_payments` (12th module)
- 11 codes: `view_own`, `view_department`, `view_zone`, `view_cross_zone`, `create`, `review`, `approve`, `reject`, `receive`, `process`, `cancel`
- Role grants (idempotent, guarded by role existence):
  - `SELF_SERVICE` → `view_own`
  - `DEPT_HEAD` → `view_department`
  - `ZONAL_HEAD` → `view_zone`
  - `HR` → all except `receive`/`process` (approval side only)
  - `ACCOUNTING` (new) → `view_cross_zone`, `receive`, `process`
  - `FINAL_AUTHORITY` + `BREAK_GLASS` → all 11

### New role: `ACCOUNTING`
- Carries settlement capability only (`receive`, `process`)
- Granted `view_cross_zone` so Accounting sees every approved case
- Deliberately **not** granted `approve`/`reject` — separation of duties: the office that approves cannot be the office that pays

---

## 2. Status Machine

```
pending
  → under_hr_review (submit)
      → hr_approved (approve)
          → sent_to_accounting (send-to-accounting)
              → processing (receive)
                  → paid (paid)  ← TERMINAL
              → payment_rejected (reject-payment)  ← TERMINAL
      → hr_rejected (reject)  ← TERMINAL
  → cancelled (cancel)  ← TERMINAL
  → hr_rejected (reject)  ← TERMINAL
```

- **TERMINAL** = `paid`, `hr_rejected`, `payment_rejected`, `cancelled`
- **CANCELLABLE** = `pending`, `under_hr_review`, `hr_approved`, `sent_to_accounting`, `processing`
- Idempotent transitions: repeating a transition returns current state unchanged

---

## 3. Five Separated Concerns

| Concern | Service | Input | Output |
|---------|---------|-------|--------|
| 1. Leave approval | (existing) | — | `LeaveRequest.status === 'approved'` (read-only here) |
| 2. Payment eligibility | `LeavePaymentEligibilityService` | employee, leave_type, policy, days, salary, loan flag | `{ok, blockers[], policy_id, snapshot}` |
| 3. Leave purchase rules | `LeavePurchaseService` | rules, requested days | `{ok, blockers[], max_days, enabled}` |
| 4. Money calculation | `LeavePaymentCalculationService` | salary, allowances, days, divisor | `{eligible_amount, calculated_amount, currency, snapshot}` |
| 5. Accounting settlement | `LeavePaymentCaseService` + `AccountingNotificationService` | case + actor | state transitions + audit + notifications |

Each writes its own **immutable snapshot** on the case row. A later policy revision or salary change never rewrites history.

---

## 4. Eligibility Checks (recorded in `eligibility_snapshot`)

1. Employee active
2. Payment type supported
3. Policy resolved for leave type
4. Policy enables payments (`payment.enabled`)
5. Leave request approved (if linked)
6. Positive day count supplied
7. **Encashment only:** leave type is paid (`is_paid_default`) OR `payment.allow_unpaid`
8. **Encashment only:** statutory maternity/paternity excluded unless `payment.allow_statutory`
9. Leave purchase rules satisfied
10. Salary basis amount supplied (no payroll table exists)
11. No prior `paid` case for same `(leave_type, leave_request, payment_type)`
12. No live duplicate case (excludes terminal states)
13. Loan outstanding block if `payment.block_on_outstanding_loan` + `loan_outstanding=true`

All checks are recorded in `eligibility_snapshot.checks[]`; blockers in `blockers[]`.

---

## 5. Calculation Formula

```
day_rate = (salary_amount + sum(allowances_included.amount)) / divisor
calculated_amount = day_rate * days
eligible_amount   = day_rate * eligible_days
```

- `divisor` from policy rule `payment.day_rate_divisor` (default `config('leave.payments.day_rate_divisor', 30)`)
- `allowances_included` may be `[{name, amount}]` or bare names; only objects with `amount` affect the sum
- Full formula, inputs, and divisor stored in `calculation_snapshot`

---

## 6. API Endpoints

| Method | URI | Permission | Notes |
|--------|-----|------------|-------|
| GET | `/leave-payments` | — (policy `viewAny`) | scoped list |
| POST | `/leave-payments` | `leave_payments.create` | create + evaluate + calculate |
| GET | `/leave-payments/{id}` | — (policy `view`) | salary snapshots only for HR-side |
| POST | `/leave-payments/{id}/evaluate` | `leave_payments.create` | re-run eligibility + calculation |
| POST | `/leave-payments/{id}/submit` | `leave_payments.create` | pending → under_hr_review |
| POST | `/leave-payments/{id}/approve` | `leave_payments.approve` | under_hr_review → hr_approved |
| POST | `/leave-payments/{id}/reject` | `leave_payments.reject` | under_hr_review → hr_rejected |
| POST | `/leave-payments/{id}/send-to-accounting` | `leave_payments.review` | hr_approved → sent_to_accounting (+ notify) |
| POST | `/leave-payments/{id}/receive` | `leave_payments.receive` | sent_to_accounting → processing |
| POST | `/leave-payments/{id}/paid` | `leave_payments.process` | processing → paid |
| POST | `/leave-payments/{id}/reject-payment` | `leave_payments.process` | processing/sent → payment_rejected |
| POST | `/leave-payments/{id}/cancel` | `leave_payments.cancel` | cancellable → cancelled |

---

## 7. Authorisation Rules (enforced in service, not just middleware)

- **No self-approval:** actor ≠ `created_by`, `submitted_by`, `employee_id`
- **Scope:** reviewer must cover case's employee via zone/department, unless holding `leave_payments.view_cross_zone`
- **HR-side payload:** `salary_basis`, `salary_amount`, `allowances_included`, `calculation_snapshot`, `eligibility_snapshot` visible only to callers holding `review` or `approve`
- **Accounting payload:** payable facts only (ref, employee name, amount, currency) — **never** leave type or reason

---

## 8. Accounting Notification

- `AccountingNotificationService::notify(case)` finds all active employees holding `leave_payments.receive` or `leave_payments.process`
- Message built from payment facts only: `Payment case #123 for John Doe is approved and ready to settle: TZS 400,000.00.`
- Idempotent per recipient via `idempotencyKey = "payment:{id}:sent-to-accounting:{employee_id}"`
- Privacy rule: **no leave type, no reason** in notification text

---

## 9. Configuration (`config/leave.php` → `payments` section)

```php
'payments' => [
    'currency' => 'TZS',
    'day_rate_divisor' => 30,
    'purchase_enabled' => true,
    'purchase_max_days' => null,
    'allow_statutory_encashment' => false,
    'block_on_outstanding_loan' => false,
],
```

Policy rules override:
- `payment.day_rate_divisor` (int)
- `payment.purchase.enabled` (bool)
- `payment.purchase.max_days` (int|null)
- `payment.allow_unpaid` (bool)
- `payment.allow_statutory` (bool)
- `payment.block_on_outstanding_loan` (bool)

---

## 10. Policy Decision — Open

**No salary/allowance/compensation/payment table exists.** `employees` carries only `payroll_no` (identifier). The only financial table is `balance_transactions` — a leave-DAY ledger, not money.

**Chosen approach:** salary basis supplied by authorised creator at case creation and snapshotted (plus `calculation_snapshot`). Nothing reads employee salary from elsewhere. This is recorded as an **unresolved policy decision** in this report; a future payroll integration would replace the creator-supplied inputs with a lookup.

---

## 11. Test Coverage (22 new tests in `Phase5PaymentTest.php`)

| Scenario | Test |
|----------|------|
| Create + evaluate + calculate | ✓ |
| Unpaid leave type blocked on submit | ✓ |
| Statutory maternity/paternity blocked on submit | ✓ |
| Leave purchase rules enforced | ✓ |
| Duplicate `(leave_request, payment_type)` prevented | ✓ |
| Submit → HR review | ✓ |
| Approve by different HR officer (no self-approval) | ✓ |
| Self-approval rejected | ✓ |
| Send to Accounting (idempotent notify) | ✓ |
| Accounting receive → process → paid | ✓ |
| Accounting reject payment | ✓ |
| Cancel before paid | ✓ |
| Terminal case immutable | ✓ |
| HR sees salary snapshots; subject does not | ✓ |
| Zone/department scope on list | ✓ |
| Idempotent create via `idempotency_key` | ✓ |
| Loan outstanding block (policy rule) | ✓ |
| Calculation snapshot contains formula + divisor | ✓ |
| Policy `day_rate_divisor` overrides config | ✓ |
| Purchase `max_days` enforced via policy rule | ✓ |
| Audit events for every transition | ✓ |
| Accounting notification omits leave type/reason | ✓ |

---

## 12. Migration / Seeder Notes

- Migration `2026_10_11_110000_create_leave_payment_cases_table.php`
- Migration `2026_10_11_110100_add_leave_payments_permissions.php` (idempotent, guards role existence)
- `DatabaseSeeder` updated:
  - `clean()` includes `leave_payment_cases`
  - `ACCOUNTING` role created
  - HR matrix includes `leave_payments` codes (no `receive`/`process`)
  - `SELF_SERVICE` gains `leave_payments.view_own`
  - Authority profile (FINAL_AUTHORITY/BREAK_GLASS) includes all 11 codes
- `PermissionService::SELF_SERVICE` constant updated (+1 code → 14)
- Catalog counts updated in tests:
  - Total codes: 51 → **62**
  - Modules: 11 → **12** (`leave_payments` added)
  - `EffectiveGrantsTest`: FINAL_AUTHORITY codes 51 → **62**

---

## 13. Files Created / Modified

### Created
- `database/migrations/2026_10_11_110000_create_leave_payment_cases_table.php`
- `database/migrations/2026_10_11_110100_add_leave_payments_permissions.php`
- `app/Models/LeavePaymentCase.php`
- `app/Support/PolicyRules.php`
- `app/Services/LeavePurchaseService.php`
- `app/Services/LeavePaymentEligibilityService.php`
- `app/Services/LeavePaymentCalculationService.php`
- `app/Services/AccountingNotificationService.php`
- `app/Services/LeavePaymentCaseService.php`
- `app/Policies/LeavePaymentCasePolicy.php`
- `app/Http/Controllers/Api/LeavePaymentController.php`
- `tests/Feature/Api/Phase5PaymentTest.php`

### Modified
- `routes/api.php` (import + `leave-payments` prefix group)
- `config/leave.php` (`payments` section)
- `config/permission_enforcement.php` (11 new enforcement references)
- `app/Services/PermissionService.php` (`SELF_SERVICE` + `leave_payments.view_own`)
- `database/seeders/DatabaseSeeder.php` (ACCOUNTING role, HR matrix, clean, authority profile)
- `tests/Feature/Api/EffectiveGrantsTest.php` (count 51→62)
- `tests/Feature/Api/RegistrationPermsTest.php` (count 51→62, module list +1)
- `tests/Feature/Api/LifecycleRegressionTest.php` (module count 11→12)
- `tests/Feature/Api/AdminGapTest.php` (BREAK_GLASS grant uses FINAL_AUTHORITY)
- `tests/Feature/Api/WorkflowChainTest.php` (delegation date extended)

---

## 14. Verification

```bash
php artisan test
# → 213 tests, 2627 assertions, 0 failures
```

All Phase 1–4 tests remain green; Phase 5 adds 22 tests covering the full payment workflow.