# Workflow Approver Selection — Deployment Checklist

Status: **implemented and verified locally. Not yet deployed.**

The Workflow Builder now stores an explicit employee for every approval step
(`workflow_steps.approver_employee_id`), chosen from the holders of the step's
approval role. Routing resolves that employee directly, keeps delegation
(recording the original approver), blocks self-approval at submission, and
records the selected approver plus the acting employee in the audit trail.

All changes are additive and backward compatible:

- Existing steps keep `approver_employee_id = NULL` and continue to resolve
  dynamically through their approval role.
- Legacy API clients may omit `strict_approvers`; the new builder always sends
  `strict_approvers: true`.
- No table, column, workflow, leave type, department, role, employee, document,
  payment case, notification, delegation or audit row is deleted or rewritten.

## 1. What ships

Backend (`backend-leave`)

- `database/migrations/2026_11_17_000001_add_approver_employee_id_to_workflow_steps.php`
  — adds nullable `workflow_steps.approver_employee_id` + index.
- `database/migrations/2026_11_17_000002_add_delegated_from_to_workflow_assignments.php`
  — adds nullable `workflow_assignments.delegated_from_employee_id` + index.
- `app/Support/ApprovalRoleMap.php`, `app/Services/ApprovalRoleHolderService.php`
  — holder resolution (catalog grants ∪ legacy grants ∪ reporting-line
  supervisors).
- `app/Http/Requests/StoreWorkflowRequest.php`, `UpdateWorkflowRequest.php`
  — strict approver requirement, employee active check, employee-holds-role
  check, duplicate sequence check.
- `app/Http/Controllers/Api/WorkflowController.php` — `role-holders` endpoint,
  approver coercion in `syncSteps`, approver in `check`/`index`/`show`.
- `app/Services/WorkflowResolver.php` — explicit approver resolution,
  delegation, self-approval refusal, final-authority selection.
- `app/Services/LeaveRequestService.php` — stores `delegated_from_employee_id`;
  enriched decision audit payload.
- `app/Models/WorkflowStep.php`, `WorkflowAssignment.php` — fillables + relations.
- `routes/api.php` — `GET /api/workflows/role-holders/{approvalRole}` and the
  `/api/approval-workflows/...` alias.
- `tests/Feature/Api/WorkflowApproverSelectionTest.php` — 8 acceptance cases.

Frontend (`Leave Management system`)

- `lib/leave-api.ts` — approver fields, `strict_approvers`, `roleHolders()`.
- `components/leave/views/workflows.tsx` — simplified builder (title, leave
  type, applies-to, approval steps), role-filtered approver dropdown,
  "Approver: Name", no assignment mode / scope / fallback / SLA / duration /
  division-unit scope controls.

## 2. Local verification (already done)

- [x] `php artisan migrate --force` on the local database — both migrations ran.
- [x] `php artisan migrate:status` — both migrations show `Ran` (batch 18).
- [x] `php artisan test --filter=WorkflowApproverSelectionTest` — 8 passed.
- [x] Related suites (workflow, queue, chain, isolation, compliance, admin) — 57 passed.
- [x] Full backend suite — 381 passed; only the 3 pre-existing date-drift waiver
      failures remain (`Phase2LeavePlanningTest` ×2, `Phase4NotificationTest` ×1).
- [x] `npx tsc --noEmit` — clean.
- [x] Live local smoke test against MySQL: login, `role-holders` list and
      `workflows/check` dry run return the selected approver name.

## 3. Migration plan (hosted — only after explicit approval)

The two migrations are additive and safe to run online; neither rebuilds a
table on MySQL. Do **not** run any seeder, rollback, `migrate:fresh`,
`db:wipe`, or data reset against the hosted database.

1. Take a database backup (hosted provider snapshot or `mysqldump`) and record
   the current highest migration batch.
2. Deploy the new backend build **with migrations not yet run** — the code is
   backward compatible, but do not leave it half-deployed.
3. Run only the pending migrations:

   ```powershell
   php artisan migrate --force
   ```

   Expected: exactly two migrations run, in this order:
   - `2026_11_17_000001_add_approver_employee_id_to_workflow_steps`
   - `2026_11_17_000002_add_delegated_from_to_workflow_assignments`

4. Confirm:

   ```powershell
   php artisan migrate:status
   ```

   Both rows must show `Ran`. No earlier rows may change.
5. Deploy the frontend build.
6. Clear caches only (no data changes):

   ```powershell
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```

   Re-apply production caches only if the platform expects it.

### Wasmer / container platforms

- Ensure the release/start command runs `php artisan migrate --force` (or an
  equivalent release phase). If the platform autoscales, run migrations exactly
  once from a release/job context, not from every web replica.
- Migrations must **fail visibly**: the container/job should exit non-zero and
  the deployment should be marked failed rather than serving traffic against a
  schema that lacks the new columns.
- If Wasmer cannot run `migrate` (read-only filesystem, missing DB egress, or
  permissions), stop and report the exact error before any code serves traffic.
- Verify `php artisan migrate:status` from a platform shell after release.

### Rollback (only if required)

Both migrations have a `down()` that drops only the two new columns/indexes.
Rolling them back loses the stored explicit approvers; existing workflows fall
back to role-based resolution, which is why the columns are nullable. Prefer
rolling the application code back first, leaving the columns in place — the old
code ignores them.

```powershell
php artisan migrate:rollback --step=2
```

## 4. Post-deploy smoke tests

Run with an administrator account on the hosted UI:

1. **Workflow Management → New workflow**
   - Only Workflow Title, Leave Type, Applies To (Entire office / Zone /
     Department) and Approval Steps are shown.
   - Choosing an approval role loads only that role's holders in the Approver
     dropdown; selecting one shows `Approver: <Name>`.
   - Changing the role clears the previously selected approver.
   - Saving without an approver is refused with a field error.
2. **Direct API checks** (replace host/token):

   ```powershell
   Invoke-RestMethod "https://<host>/api/workflows/role-holders/department_head" -Headers @{ Authorization = "Bearer $token" }
   ```

   Returns only active employees who hold the role.

3. **Routing** — submit a request whose workflow names a specific approver.
   The inbox shows the request for that employee (or their active delegate),
   not for the department head resolved dynamically.
4. **Delegation** — with an active delegation, the delegate can act; the
   assignment and audit payload keep the original approver.
5. **Self-approval** — a request whose step names the requester is rejected at
   submission with a message naming the step and the person.
6. **Audit** — the decision audit row shows `step_id`, `step_order`,
   `step_role`, `selected_approver_id`, `selected_approver_name`,
   `acting_employee_id`, `acting_employee_name`, `delegated`, `decision`,
   `comments`, `decided_at`.
7. **Backward compatibility** — open an older workflow that has no explicit
   approver; it still lists and submits through its role. Selecting approvers
   and saving migrates it to the explicit model without touching history.

## 5. Data-safety rules

- Do not reset local or hosted databases.
- Do not seed demo data in production.
- Do not delete workflows, leave types, departments/divisions/units, roles, or
  employees.
- Preserve workflow versions, audit history, documents, payment cases,
  notifications and delegations.
- The new columns are nullable and unfenced by foreign keys on purpose: a
  cascade could silently rewrite live approval steps, and SQLite cannot add a
  foreign key without rebuilding the table.
- `assignment_mode` and `fallback_approval_role_id` remain in the schema for
  backward compatibility even though the simplified builder no longer exposes
  them; when an explicit approver is stored, the API coerces the step to
  `assigned_approver` and clears the fallback.

## 6. Known unrelated failures

The full backend suite still reports three date-drift waiver tests:

- `Phase2LeavePlanningTest` — waiver review/approve (2 tests)
- `Phase4NotificationTest` — waiver decision notification (1 test)

They fail because seeded waiver fixtures have expired relative to the current
date. They are unrelated to this change and were not modified.
