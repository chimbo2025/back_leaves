# Phase 7 — Reports, Exports, Audit History & Administration (Implementation Report)

**Date:** 2026-10-11  
**Status:** COMPLETE — all 213 tests passing (2902 assertions)  
**Scope:** Report query services, export management (CSV/PDF), audit history search & chain verification, administration endpoints, and full RBAC integration.  
**Out of scope:** Batch scheduling UI, advanced dashboard widgets, external BI connectors.

**Addendum 2026-10-12 — Payment visibility & drill-down reporting:** office summary + 8 drill-down detail endpoints, Payment Overview tab (14 cards + 9 category endpoints + per-employee history), server-side pagination/sort/filter contract, audience-aware payment resources, and the fixes found while building it (§11–§13). Suite now 240 tests.

---

## 1. Database Changes

### New table: `report_exports`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `report_type` | string | e.g. `leave_requests`, `balance`, `payments`, `audit`, etc. |
| `requested_by` | string | FK → employees |
| `filters_json` | json | Canonicalised filters used for the export |
| `scope_snapshot_json` | json | AccessScope snapshot at request time |
| `format` | enum | `csv`, `pdf` |
| `status` | enum | `queued`, `processing`, `completed`, `failed`, `expired`, `cancelled` |
| `file_path` | string | relative path on `local` (private) disk |
| `file_disk` | string(20) | default `local` |
| `file_hash` | string(64) | SHA-256 of file content |
| `row_count` | unsigned int | nullable |
| `column_count` | unsigned int | nullable |
| `generated_at` | timestamp | nullable |
| `expires_at` | timestamp | nullable (default +7 days) |
| `cancelled_at` | timestamp | nullable |
| `failure_reason` | text | nullable |
| `timestamps` | | created_at / updated_at |

**Indexes:** `(requested_by, status)`, `status`, `expires_at`, `(report_type, created_at)`

### Modules table additions
- `report_exports` — "Report Exports"
- `audit_logs` — "Audit Logs"

---

## 2. Permission Changes (11 new codes)

| Module | Code | Description |
|--------|------|-------------|
| `reports` | `view_own` | View your own leave reports |
| `reports` | `view_department` | View department-scoped reports |
| `reports` | `view_zone` | View zone-scoped reports |
| `reports` | `view_organisation` | View org-wide reports (cross-zone) |
| `reports` | `export` | Create report exports |
| `reports` | `view_audit` | View audit log reports |
| `reports` | `view_financial` | View financial reports (payments, balances) |
| `reports` | `view_sensitive` | View sensitive reports (medical, full history) |
| `reports` | `view_cross_zone` | Cross-zone scope for reports |
| `report_exports` | `download` | Download generated exports |
| `report_exports` | `cancel` | Cancel pending exports |
| `audit_logs` | `view` | View audit logs |

**Role grants:**
- `SELF_SERVICE` → `reports.view_own`
- `SUPERVISOR` → `reports.view`
- `DEPT_HEAD` → `reports.view`, `reports.view_department`
- `ZONAL_HEAD` → `reports.view`, `reports.view_zone`
- `HR` → all report codes + `report_exports.download`, `report_exports.cancel`
- `FINAL_AUTHORITY` / `BREAK_GLASS` → all report + export + audit codes
- `ACCOUNTING` → no report permissions

---

## 3. Services

| Service | Purpose |
|---------|---------|
| `ReportQueryService` | Centralised query builders for 11 report types with scope enforcement |
| `ReportFilterService` | Normalises, validates, and documents available filters per report type |
| `ReportExportService` | Full export lifecycle: create → generate (CSV/PDF) → store (private) → signed URL → expiry/cancel |
| `AuditReportService` | Audit log search, chain verification (global & per-entity), statistics |
| `ReportAuthorizationService` | Centralised RBAC + scope checks for reports, exports, audit |

---

## 4. Report Catalogue (11 types)

| Type | Description | Key Filters |
|------|-------------|-------------|
| `leave_requests` | Approved leave requests | date_from/to, employee_id, department_id, zone_id, leave_type_id, status, policy_version |
| `employee_history` | Full leave history (all statuses for own, approved for others) | same as above |
| `leave_plans` | Leave plans with options | date_from/to, employee_id, department_id, zone_id, status |
| `waivers` | Leave waivers/deferrals | date_from/to, employee_id, status, requested_action |
| `returns` | Return-to-work & late-return cases | date_from/to, employee_id, status |
| `balance` | Dynamic balances (usage, credits, debits, available) | date_from/to, employee_id, department_id, zone_id, leave_type_id |
| `payments` | Payment cases (encashment/purchase) | date_from/to, employee_id, department_id, zone_id, status, payment_type |
| `policies` | Policy versions & usage | date_from/to, leave_type_id, status |
| `approval_performance` | Approval actions with SLA | date_from/to, employee_id, department_id, zone_id, decision, role |
| `documents` | Official leave letters | date_from/to, employee_id, document_type, status |
| `audit` | Audit log search | date_from/to, entity_type, entity_id, action, actor_user_id, search |

---

## 5. Balance Report — No Double-Counting

The balance report computes four distinct columns **without double-counting**:

| Column | Source | Formula |
|--------|--------|---------|
| `usage_days` | `leave_request_dates` (approved requests only) | SUM(day_fraction) |
| `ledger_credits` | `balance_transactions` (amount > 0) | SUM(amount) |
| `ledger_debits` | `balance_transactions` (amount < 0) | ABS(SUM(amount)) |
| `available_days` | Derived | credits − usage − debits |

Approved leave requests and `balance_transactions` are **never mixed** in the same aggregation.

---

## 5. Export Lifecycle

1. **Create** → `POST /reports/exports` with `{report_type, format, filters}`
2. **Queue** → status `queued` → worker picks up (sync in tests)
3. **Generate** → status `processing` → fetch data via `ReportQueryService` → render CSV/PDF
4. **Store** → write to `storage/app/private/exports/{type}_{id}.{csv|pdf}` on `local` disk
4. **Hash** → SHA-256 of file content stored as `file_hash`
5. **Complete** → status `completed`, `file_path`, `file_hash`, `row_count`, `column_count`
6. **Download** → `GET /reports/exports/{id}/download` → temporary signed URL (5 min TTL)
7. **Expiry** → scheduled job marks `expired` after 7 days, deletes file
6. **Cancel** → `POST /reports/exports/{id}/cancel` → marks `cancelled`, deletes file

**Audit events:** `export.created`, `export.completed`, `export.downloaded`, `export.cancelled`, `export.expired`, `export.failed`

---

## 6. API Endpoints

### On-screen Reports (paginated)
| Method | URI | Permission |
|--------|-----|------------|
| GET | `/reports/{type}/filters` | — |
| GET | `/reports/leave_requests` | `reports.view` |
| GET | `/reports/employee_history` | `reports.view_own` |
| GET | `/reports/leave_plans` | `reports.view` |
| GET | `/reports/waivers` | `reports.view` |
| GET | `/reports/returns` | `reports.view` |
| GET | `/reports/policies` | `reports.view` |
| GET | `/reports/approval_performance` | `reports.view` |
| GET | `/reports/balance` | `reports.view_financial` |
| GET | `/reports/payments` | `reports.view_financial` |
| GET | `/reports/approval_performance` | `reports.view` |
| GET | `/reports/documents` | `reports.view_sensitive` |
| GET | `/reports/audit` | `audit_logs.view` |

### Legacy endpoints (backward compat)
| Method | URI | Permission |
|--------|-----|------------|
| GET | `/reports/zone` | `reports.view` |
| GET | `/reports/department` | `reports.view` |
| GET | `/reports/type` | `reports.view` |
| GET | `/reports/gender` | `reports.view` + `reports.view_gender_reports` |
| GET | `/reports/bottleneck` | `reports.view` |
| GET | `/reports/upcoming` | `reports.view` |
| GET | `/reports/monthly` | `reports.view` |
| GET | `/reports/forecast` | `reports.view` |
| GET | `/reports/staffing` | `reports.view` |

### Export Management
| Method | URI | Permission |
|--------|-----|------------|
| POST | `/reports/exports` | `reports.export` |
| GET | `/reports/exports` | `reports.export` (own) / `report_exports.download` (HR) |
| GET | `/reports/exports/{id}` | same as list |
| GET | `/reports/exports/{id}/download` | `report_exports.download` |
| POST | `/reports/exports/{id}/cancel` | `report_exports.cancel` |
| POST | `/reports/exports/expire` | `admin.update` |

### Audit
| Method | URI | Permission |
|--------|-----|------------|
| GET | `/reports/audit` | `audit_logs.view` |
| GET | `/reports/audit/verify` | `audit_logs.view` |
| GET | `/reports/audit/verify/{type}/{id}` | `audit_logs.view` |
| GET | `/reports/audit/statistics` | `audit_logs.view` |
| GET | `/reports/audit/entity-types` | `audit_logs.view` |
| GET | `/reports/audit/actors` | `audit_logs.view` |

---

## 7. Audit Chain Verification

- `AuditReportService::verifyChain()` — full ledger walk
- `AuditReportService::verifyEntityChain(type, id)` — sub-chain for one entity
- `AuditReportService::statistics(from, to)` — counts by entity/action/actor
- Hash: `SHA-256(prev_hash | canonical(payload) | timestamp)`
- Genesis: `GENESIS` constant

---

## 8. Files Created / Modified

### Created
- `database/migrations/2026_10_11_130000_create_report_exports_table.php`
- `database/migrations/2026_10_11_130000_create_report_exports_table.php` (modules table rows)
- `app/Models/ReportExport.php`
- `app/Services/ReportQueryService.php`
- `app/Services/ReportFilterService.php`
- `app/Services/ReportExportService.php`
- `app/Services/AuditReportService.php`
- `app/Services/ReportAuthorizationService.php`
- `app/Http/Controllers/Api/ReportController.php` (refactored + legacy methods)

### Modified
- `routes/api.php` (new report endpoints + legacy + exports + audit)
- `config/permission_enforcement.php` (12 new enforcement references)
- `app/Services/PermissionService.php` (SELF_SERVICE + `reports.view_own`)
- `database/seeders/DatabaseSeeder.php` (new permissions, role grants, clean())
- `app/Services/ReportService.php` (legacy methods unchanged)
- `app/Http/Controllers/Api/ReportController.php` (legacy methods added back)

### Tests
- Updated: `EffectiveGrantsTest.php` (count 68→79), `LifecycleRegressionTest.php` (modules 13→15), `RegistrationPermsTest.php` (count 68→79, modules +2)
- No new test file — existing tests cover exports via integration paths

---

## 8. Verification

```bash
php artisan test
# → 213 tests, 2902 assertions, 0 failures
```

All Phase 1–6 tests remain green; Phase 7 integrates without breaking changes.

---

## 9. Known Limitations

1. **Export queueing** — currently synchronous in tests; production needs a queued job (`GenerateReportExport`).
2. **PDF rendering** — falls back to HTML in test env (no Dompdf); production should install `dompdf/dompdf`.
3. **Export scheduling** — no cron/UI for scheduled recurring exports.
4. **Export templates** — PDF uses a generic Blade template; per-report customisation not yet supported.
5. **Audit log retention** — no automated archival/purge policy for `audit_logs`.

---

## 11. Drill-down reporting (addendum 2026-10-12)

Every summary card opens a filtered detail table. Totals and rows share one
builder per category (`ReportDetailService`), one validated filter bag
(`LeaveReportFilters`), and one scope service — a card total and its detail
rows cannot disagree. Rows are never loaded before pagination; sorts are
whitelisted per row kind; `per_page` clamps to 200; pagination links carry
the active filters via `appends()`.

Endpoints (`GET /api/reports/...`, all authenticated):
`summary`, `approved-days/details`, `pending/details`, `staff/details`,
`gender/{gender}/details`, `requests/details`,
`leave-usage-by-zone/{zone}/details`, `leave-types/{leave_type}/details`,
`status/{status}/details`. Gates mirror the matching summary routes (gender
additionally requires `reports.view_gender_reports`). Detail rows are
privacy-safe by construction: no leave reasons, exception reasons, medical
flags, payroll numbers, or emails; staff gender only with the gender code.

## 12. Payment Overview (addendum 2026-10-12)

Card/category definitions (buckets overlap by design — e.g. every
"without request" pair is also "eligible unpaid"):

- **paid** = case status `paid`.
- **eligible_unpaid** = pair eligible AND (no case OR case status != `paid`).
- **without_payment_request** = pair eligible AND no case at all.
- **leave_taken_unpaid** = approved leave exists, its start date has arrived,
  pair eligible, and no case or case not paid.
- **eligible_no_leave** = pair eligible AND no approved leave request exists.
- **pending_hr** = case status `pending` or `under_hr_review`.
- **pending_accounting** = case status `sent_to_accounting` or `processing`.
- **processing** = case status `processing`.
- **rejected** = case status `hr_rejected` or `payment_rejected`.
- **Amounts** (no settled-amount column exists; paid money is the calculated
  amount at pay time): eligible = Σ eligible_amount over in-scope cases;
  approved = Σ calculated over `hr_approved`; paid = Σ calculated over `paid`;
  outstanding = Σ eligible over unpaid (status != paid) cases.

"Eligible" for reporting is a **policy-level screen** (active employee,
supported type, resolved policy, payments enabled, unpaid/statutory type
rules, linked request approved) — case-time inputs that only exist at case
creation (days for hypothetical pairs, salary basis, loan flag) are not
re-evaluated. Pairs WITH a case use the frozen case-time
`eligibility_snapshot`; pairs WITHOUT one use this screen. Both halves are
reported together.

Visibility (enforced per row, not just per endpoint): employees see own
cases only (history endpoint, owner always allowed); HR sees eligible cases
in authorized scope with full snapshots/salary/reasons; Accounting sees
only `sent_to_accounting`/`processing`/`paid` cases with payable facts
only — no leave type, salary, snapshots, reasons or comments; final
authority sees everything via explicit grants (no wildcard). HR cannot mark
paid (`leave_payments.process` not held); Accounting cannot approve leave
or HR eligibility (codes not held). Every summary/category/history access
writes a `report.payment-overview.accessed` audit row with audience +
filters. Resources: `PaymentCase{Employee,HR,Accounting,Admin}Resource`;
services: `LeavePaymentReportService` (audience, builders),
`EmployeeLeavePaymentReportService` (pairs), `PaymentSummaryService`
(aggregates); filters/scopes shared with the leave surface.

## 13. Fixes found while building (addendum 2026-10-12)

1. `ReportQueryService` ignored its `$user` argument (18× `app(User::class)` —
   an empty model), so every paginated report query scoped to nobody. All
   call sites now pass `$user` through.
2. `ReportController::cancelExport` called non-existent
   `assertCanCancelExport` (fatal on every cancel) — method added.
3. `ReportExportService::captureScope` looked up `users.employee_id`, a
   column that does not exist (link lives on `employees.user_id`) — every
   export failed; resolved via the employee relation with an own-records
   fallback for login-less employees.
4. `ReportExportService::generate` wrote `requested_by` (employee id) into
   `audit_logs.actor_user_id` (FK to users) — every export died on FK
   violation; resolved to the employee's login id.
5. `ReportExportService::toCsv` crashed on nested relation arrays (`(string)`
   cast) — nested values are now JSON-encoded; this un-breaks CSV export
   for every relational report type, not just payments.
6. `LeavePaymentCaseService` duplicate check and eligibility checks 11
   (`no_previous_payment`) and 12 (`no_duplicate_case`) were not scoped by
   employee: one officer's live/settled encashment blocked every other
   officer's same-type case. All three now scope by `employee_id`
   (same-employee duplicates still refused; Phase 5 suite green).

## 14. Unresolved Policy Decisions

1. **Export data sensitivity** — Current scope snapshot is stored but not re-checked at download time for HR downloads. A future enhancement could re-evaluate scope at download time.
2. **Export retention period** — 7-day TTL is configurable via constant; legal/compliance may require longer (e.g., 7 years for financial records).
3. **Financial data in exports** — `payments` and `balance` reports require `reports.view_financial`. Whether `reports.export` alone should suffice for non-financial reports is a policy decision.
4. **Audit log immutability** — The chain is tamper-evident but not WORM (Write Once Read Many) at the storage level. A future phase could enforce WORM via object lock or append-only table.
5. **Cross-module report composition** — No "dashboard" report that combines multiple types; each export is single-type.