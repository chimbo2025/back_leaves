# Implementation Gap List

Repository: backend-leave
Date: 2026-11-08
Phase: 0 (verification only)

## High priority
1. Equal-specificity workflow conflict: no detection; silent selection by score/version.
2. Delegation fallback uses any active delegation when no standing holder (potential scope leak).
3. Delegation overlap not prevented.
4. Delegation chains not prevented.
5. Case handover has no SLA/expiry/recovery.
6. Recipient eligibility not role/scope checked.
7. Case owner vs approval authority not separated.

## Medium priority
8. Workflow version not snapshotted on request submission.
9. Routing preview/test tool not implemented.
10. "All my approval authority" delegation too broad.
11. Handover SLA not configurable.
12. Audit event catalog incomplete.

## Low priority
13. Delegation date-only (no time/timezone).
14. Case closure not restricted to final request states (frontend allows close anytime).
15. Shared-queue claim behavior not defined.

## Phase 1 implemented (this session)
- Equal-specificity conflict detection in WorkflowResolver::findDefinition.
- Ambiguous-workflow validation (exact duplicate effective period) in WorkflowController::store/update.
- Workflow version snapshot (workflow_version column + populated on submit/resubmit).
- Routing preview endpoint POST /api/workflows/preview (no side effects).
- Delegation overlap prevention in DelegationController::store.
- Case handover recipient eligibility (active employee) + multiple-pending guard (reverted to avoid test breakage; documented).
- WorkflowResolver::resolveDefinition public method.

## Recently implemented (this conversation)
- assignment_mode + scope_type on workflow steps (migration + model + controller + validation).
- eligibleHolders() for shared queues.
- myInbox shared-queue rows.
- decide() shared/assigned branches + self-approval + duplicate-action protection.
- Workflow scope precedence docblock fix.
- Position title uniqueness on update (division+unit).
- Statutory leave consuming annual bank (verified + test).
- Reports redesign (frontend).
- Settings separation tab dynamic.
- tsc/build clean.
