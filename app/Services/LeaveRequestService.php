<?php

namespace App\Services;

use App\Events\LeaveRequestApproved;
use App\Events\LeaveRequestRejected;
use App\Events\LeaveRequestReturned;
use App\Events\LeaveRequestSubmitted;
use App\Models\ApprovalAction;
use App\Models\Delegation;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDate;
use App\Models\LeaveType;
use App\Models\WorkflowAssignment;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Request lifecycle — submit, resubmit, decide (approved|rejected|returned),
 * withdraw — with the blueprint Part 3 handover gate on final approval.
 */
class LeaveRequestService
{
    public const SUBMITTABLE_STATUSES = ['draft', 'submitted'];

    public const RESUBMITTABLE_STATUSES = ['returned_for_correction', 'withdrawn'];

    public function __construct(
        protected PolicyService $policy,
        protected WorkflowResolver $resolver,
        protected NotificationService $notifications,
        protected AuditService $audit,
        protected CaseHandoverService $caseService,
        protected ReturnToWorkService $returns,
        protected PermissionService $permissions,
        protected LeaveApprovalSignatureService $signatures,
    ) {}

    public function createDraft(string $employeeId, array $data): LeaveRequest
    {
        $leaveTypeId = $data['leave_type_id'];
        $start = $data['start_date'];
        $end = $data['end_date'] ?? null;
        $days = $end ? $this->policy->dayCount($start, $end) : null;

        return LeaveRequest::create([
            'ref' => $this->nextRef(),
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'is_half_day' => (bool) ($data['is_half_day'] ?? false),
            'is_emergency_exception' => (bool) ($data['is_emergency_exception'] ?? false),
            'exception_reason' => $data['exception_reason'] ?? null,
            'reason' => $data['reason'] ?? null,
            'status' => 'draft',
        ]);
    }

    /**
     * Edit a draft before submission — requester-owned, status must stay
     * 'draft' so the chain never holds stale dates (policy is re-resolved at
     * submit time, so policy_id is cleared for recomputation).
     *
     * @throws ValidationException
     */
    public function updateDraft(LeaveRequest $request, string $employeeId, array $data): LeaveRequest
    {
        if ($request->employee_id !== $employeeId) {
            throw ValidationException::withMessages(['authorization' => 'Unaweza kuhariri maombi yako mwenyewe pekee.']);
        }
        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Ni maombi ya rasimu pekee yanayoweza kuhaririwa (hali ya sasa: {$request->status})."]);
        }

        $end = $data['end_date'] ?? null;
        $days = $end ? $this->policy->dayCount($data['start_date'], $end) : null;

        $request->update([
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $end,
            'days' => $days,
            'is_half_day' => (bool) ($data['is_half_day'] ?? false),
            'is_emergency_exception' => (bool) ($data['is_emergency_exception'] ?? false),
            'exception_reason' => $data['exception_reason'] ?? null,
            'reason' => $data['reason'] ?? null,
            'policy_id' => null,
        ]);

        return $request->fresh(['employee', 'leaveType', 'policy']);
    }

    /**
     * @throws ValidationException
     */
    public function submit(LeaveRequest $request, ?string $actorUserId = null): LeaveRequest
    {
        $employee = $request->employee;
        $errors = $this->validateForSubmission($request, $employee);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $policy = $this->policy->policyEffectiveAt($request->leave_type_id, $request->start_date);
        if (! $policy) {
            throw ValidationException::withMessages(['leave_type_id' => 'Hakuna sera hai iliyopo kwa aina hii ya likizo.']);
        }

        DB::transaction(function () use ($request, $employee, $policy, $actorUserId) {
            $elig = app(LeaveEligibilityService::class)->check($employee, $request->leave_type_id, $request->start_date->toDateString(), $request->end_date?->toDateString(), [
                'is_emergency_exception' => (bool) $request->is_emergency_exception,
            ]);
            $resolvedDef = $this->resolver->resolveDefinition($request);

            $request->update([
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
                'workflow_version' => $resolvedDef?->version,
                'policy_snapshot' => $policy->toArray(),
                'eligibility_checked_at' => now(),
                'eligibility_blockers' => $elig['blockers'],
                'eligibility_ok' => $elig['ok'],
                'medical_required' => $policy->requires_documents,
                'status' => 'submitted',
                'submitted_at' => now(),
                'decided_at' => null,
            ]);
            $this->storeDates($request);

            $chain = $this->resolver->resolveWorkflow($request);
            if (empty($chain)) {
                throw ValidationException::withMessages(['workflow' => 'Hakuna mwidhinishaji aliyepatikana kwa ombi hili. Wasiliana na HR.']);
            }
            $request->assignments()->delete();
            $firstAssignment = null;
            foreach ($chain as $entry) {
                $isShared = ($entry['assignment_mode'] ?? 'assigned_approver') === 'shared_queue';
                if (! $isShared && ! $entry['assignee_id']) {
                    continue;
                }
                $assignment = WorkflowAssignment::create([
                    'request_id' => $request->id,
                    'step_id' => $entry['step_id'],
                    'assigned_employee_id' => $entry['assignee_id'],
                    'delegated_from_employee_id' => $entry['delegated_from_id'] ?? null,
                    'assigned_role_id' => $entry['role'],
                    'status' => $firstAssignment === null ? 'pending' : 'queued',
                    'due_at' => $entry['due_at'],
                ]);
                $firstAssignment ??= $assignment;
            }

            $first = $firstAssignment;
            $request->update([
                'status' => $first ? 'under_review' : 'submitted',
                'current_owner_id' => $first?->assigned_employee_id,
            ]);

            if ($first && $first->assignee) {
                event(new LeaveRequestSubmitted($request, $first->assignee));
            }

            $this->audit->append('leave_request', (string) $request->id, 'submit', $actorUserId, [
                'ref' => $request->ref,
                'status' => $request->status,
                'policy_version' => $policy->version,
                'days' => $request->days,
            ]);
        });

        return $request->fresh(['assignments', 'employee', 'leaveType', 'policy']);
    }

    /**
     * Re-submission after a return-for-correction never resets completed
     * steps (blueprint §3.2: preserve the requester's place in the chain).
     */
    public function resubmit(LeaveRequest $request, ?string $actorUserId = null): LeaveRequest
    {
        if (! in_array($request->status, self::RESUBMITTABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => "Ni maombi yaliyorudishwa au kuondolewa pekee yanayoweza kuwasilishwa tena (hali ya sasa: {$request->status})."]);
        }

        $errors = $this->validateForSubmission($request, $request->employee);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($request, $actorUserId) {
            $policy = $this->policy->policyEffectiveAt($request->leave_type_id, $request->start_date->toDateString());
            $elig = app(LeaveEligibilityService::class)->check($request->employee, $request->leave_type_id, $request->start_date->toDateString(), $request->end_date?->toDateString(), [
                'is_emergency_exception' => (bool) $request->is_emergency_exception,
            ]);
            $resolvedDef = $this->resolver->resolveDefinition($request);

            $request->update([
                'policy_id' => $policy?->id,
                'policy_version' => $policy?->version,
                'workflow_version' => $resolvedDef?->version,
                'policy_snapshot' => $policy ? $policy->toArray() : null,
                'eligibility_checked_at' => now(),
                'eligibility_blockers' => $elig['blockers'],
                'eligibility_ok' => $elig['ok'],
                'medical_required' => $policy ? $policy->requires_documents : null,
                'status' => 'submitted',
                'submitted_at' => now(),
                'decided_at' => null,
            ]);
            $this->storeDates($request);

            $chain = $this->resolver->resolveWorkflow($request);
            // Keep approved steps; archive leftover pending/queued steps, then
            // re-create unresolved steps with only the next approver pending.
            $request->assignments()->where('status', '!=', 'done')->update(['status' => 'skipped']);
            $firstAssignment = null;
            foreach ($chain as $entry) {
                $isShared = ($entry['assignment_mode'] ?? 'assigned_approver') === 'shared_queue';
                if (! $isShared && ! $entry['assignee_id']) {
                    continue;
                }
                $already = WorkflowAssignment::where('request_id', $request->id)
                    ->where('step_id', $entry['step_id'])
                    ->where('status', 'done')
                    ->exists();
                if ($already) {
                    continue;
                }
                $assignment = $request->assignments()->create([
                    'step_id' => $entry['step_id'],
                    'assigned_employee_id' => $entry['assignee_id'],
                    'delegated_from_employee_id' => $entry['delegated_from_id'] ?? null,
                    'assigned_role_id' => $entry['role'],
                    'status' => $firstAssignment === null ? 'pending' : 'queued',
                    'due_at' => $entry['due_at'],
                ]);
                $firstAssignment ??= $assignment;
            }

            $first = $firstAssignment;
            $request->update(['status' => 'under_review', 'current_owner_id' => $first?->assigned_employee_id]);
            if ($first && $first->assignee) {
                event(new LeaveRequestSubmitted($request, $first->assignee));
            }
            $this->audit->append('leave_request', (string) $request->id, 'resubmit', $actorUserId, ['ref' => $request->ref]);
        });

        return $request->fresh(['assignments', 'employee', 'leaveType', 'policy']);
    }

    /**
     * @return array{toast: string, blocked_by_handover: bool}
     */
    /**
     * @param  array{signature_hash?:?string,signature_type?:?string,signature_image?:?string,is_paid?:?bool,ip?:?string,user_agent?:?string}  $signature
     */
    public function decide(LeaveRequest $request, Employee $actor, string $decision, ?string $comments = null, ?string $endDate = null, array $signature = []): array
    {
        if (! in_array($decision, ['approved', 'rejected', 'returned'], true)) {
            throw ValidationException::withMessages(['decision' => "Uamuzi si sahihi '{$decision}'."]);
        }

        $pending = $request->assignments()
            ->whereIn('status', ['pending', 'queued'])
            ->orderBy('id')
            ->get();

        $current = $pending->first();
        $pendingLeft = $pending->slice(1);

        // Only the head of the chain is decidable. Matching any pending
        // assignment the actor happens to hold let an approver jump the queue:
        // with steps [ZONAL_HEAD -> e8, HR -> e1], e1 could approve the HR step
        // while the regional sign-off in front of it was still pending, so the
        // request skipped a reviewer entirely. This ownership check runs first
        // so a stranger still hears "not assigned to you" (422), not a
        // permission error.
        if (! $current) {
            throw ValidationException::withMessages([
                'authorization' => 'Hakuna hatua zinazosubiri kuamuliwa.',
            ]);
        }

        $isSharedQueue = $current->step && ($current->step->assignment_mode ?? 'assigned_approver') === 'shared_queue';
        $isDelegate = false;

        if ($isSharedQueue) {
            // Verify the actor is an eligible holder of the role within scope.
            $resolver = app(WorkflowResolver::class);
            $holders = $resolver->eligibleHolders($request->employee, $current->assigned_role_id, $current->step->scope_type ?? 'requester_department');
            $eligible = $holders->contains('id', $actor->id);
            // Delegation: an active delegate of one of the eligible holders may
            // also act.
            if (! $eligible) {
                $isDelegate = $this->activeDelegationExists(
                    $actor,
                    $holders->pluck('id')->all(),
                    $current->assigned_role_id,
                );
                $eligible = $isDelegate;
            }
            if (! $eligible) {
                throw ValidationException::withMessages([
                    'authorization' => 'Hatua hii haijagawiwa kwako — huwezi kuiamua.',
                ]);
            }
        } else {
            // Assigned approver mode: ownership or an active delegation by the
            // *person this step is assigned to*.
            //
            // The assignment may store the delegate as the assignee (an active
            // delegation at submission time routes the work to the delegate).
            // In that case the assignee is acting in that delegate capacity,
            // not as the originally selected approver.
            //
            // The delegation must also belong to the expected approver for
            // this assignment. Matching any delegation for the role let a
            // same-role delegate of a *different* approver (another department
            // head, for example) decide a step that was never theirs.
            $expectedApproverId = $current->delegated_from_employee_id ?: $current->assigned_employee_id;
            $isDelegate = $expectedApproverId !== null
                && $this->activeDelegationExists($actor, [$expectedApproverId], $current->assigned_role_id);

            if ($current->assigned_employee_id !== $actor->id && ! $isDelegate) {
                throw ValidationException::withMessages([
                    'authorization' => 'Hatua hii haijagawiwa kwako — huwezi kuiamua.',
                ]);
            }
        }

        // Self-approval guard.
        if ($actor->id === $request->employee_id) {
            throw ValidationException::withMessages([
                'authorization' => 'Huwezi kuidhinisha ombi lako mwenyewe.',
            ]);
        }

        if ($decision === 'approved' && $endDate) {
            if ($pendingLeft->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'end_date' => 'Ni mwidhinishaji wa mwisho pekee anayeweza kuweka tarehe ya kurudi na muda wa likizo.',
                ]);
            }
            $this->validateApprovedPeriod($request, $endDate);
        }

        // Capability AND ownership: assignment alone no longer bypasses the
        // matrix. Each decision kind maps to its own code so every switch in
        // the RBAC matrix means exactly one thing. Written as explicit
        // literals (not a variable) so the catalog-integrity scanner sees
        // all three codes as wired.
        //
        // Delegates are exempt from the code check: an active, in-window
        // delegation for this step's role IS their capability — it was
        // authorized at creation time (the delegator had to genuinely hold
        // the role), and delegates need hold no role codes of their own.
        // The signature ceremony on approvals still applies to everyone.
        // `$isDelegate` was scoped above to the approver this assignment
        // expects, so an unrelated same-role delegate is not exempt.
        if (! $isDelegate) {
            // NOTE: $user (lowercase, single variable) is deliberate — the
            // catalog-integrity scanner matches ->can($var, 'module',
            // 'action') and would miss $actor->user.
            $user = $actor->user;
            $hasDecisionCode = $decision === 'approved'
                ? $this->permissions->can($user, 'requests', 'approve')
                : ($decision === 'rejected'
                    ? $this->permissions->can($user, 'requests', 'reject')
                    : $this->permissions->can($user, 'requests', 'return'));
            if (! $hasDecisionCode) {
                $need = ['approved' => 'approve', 'rejected' => 'reject', 'returned' => 'return'][$decision];
                throw new AuthorizationException("Ruhusa haipo: requests.{$need}.");
            }
        }

        $mine = $current;
        $isFinalStep = $pendingLeft->isEmpty();

        // The return date is decided by the approver: required to finalize an
        // approval, optional (but usable) on intermediate steps.
        if ($decision === 'approved' && $isFinalStep && ! $endDate && $request->end_date === null) {
            throw ValidationException::withMessages(['end_date' => 'Weka tarehe ya kurudi (tarehe ya kurejea kazini) kabla ya kuidhinisha likizo hii.']);
        }

        return DB::transaction(function () use ($request, $actor, $decision, $comments, $endDate, $mine, $signature, $isFinalStep) {
            $updated = WorkflowAssignment::where('id', $mine->id)
                ->where('status', 'pending')
                ->update(['status' => 'done', 'decided_at' => now()]);
            if ($updated === 0) {
                throw ValidationException::withMessages(['authorization' => 'Hatua hii tayari imeamuliwa.']);
            }
            $mine->refresh();
            $action = ApprovalAction::create([
                'assignment_id' => $mine->id,
                'actor_employee_id' => $actor->id,
                'decision' => $decision,
                'comments' => $comments,
                'workflow_rule_used' => $mine->step_id,
                'acted_at' => now(),
            ]);

            if ($decision === 'approved' && $endDate) {
                $days = $this->policy->dayCount($request->start_date->toDateString(), $endDate);
                $request->update(['end_date' => $endDate, 'days' => $days]);
                $this->storeDates($request);
            }

            $outcome = $this->advanceAfterDecision($request, $actor, $decision, $comments, $mine);
            if (! $outcome['blocked_by_handover']) {
                // Final approval carries the HR response letter payload (when
                // the acting step is the HR reviewer). Persist it only after
                // the chain has genuinely completed — a handover-blocked final
                // approval must not leave a dispatched letter behind.
                if ($decision === 'approved' && $isFinalStep) {
                    $letter = [];

                    if (array_key_exists('is_paid', $signature)) {
                        $letter['is_paid'] = $signature['is_paid'];
                    }
                    if (array_key_exists('reference_number', $signature) && $signature['reference_number'] !== null && $signature['reference_number'] !== '') {
                        $letter['reference_number'] = $signature['reference_number'];
                    }
                    if (array_key_exists('approved_days', $signature) && $signature['approved_days'] !== null) {
                        $letter['approved_days'] = (int) $signature['approved_days'];
                    }
                    if (array_key_exists('letter_body', $signature) && $signature['letter_body'] !== null && $signature['letter_body'] !== '') {
                        $letter['letter_body'] = $signature['letter_body'];
                        $letter['letter_issued_at'] = now();
                    }

                    if ($letter !== []) {
                        $request->update($letter);
                    }
                }

                // Audit the whole decision context: which configured step ran,
                // who the builder chose as approver, who actually acted (the
                // delegate when a delegation was active), the decision, the
                // comment and the timestamp.
                $selectedApproverId = $mine->delegated_from_employee_id ?: $mine->assigned_employee_id;

                $this->audit->append('leave_request', (string) $request->id, $decision, $actor->user_id, [
                    'ref' => $request->ref,
                    'step_id' => $mine->step_id,
                    'step_order' => $mine->step?->step_order,
                    'step_role' => $mine->assigned_role_id,
                    'selected_approver_id' => $selectedApproverId,
                    'selected_approver_name' => $mine->delegatedFrom?->full_name ?? $mine->assignee?->full_name,
                    'acting_employee_id' => $actor->id,
                    'acting_employee_name' => $actor->full_name,
                    'delegated' => $mine->delegated_from_employee_id !== null,
                    'decision' => $decision,
                    'return_date' => $request->end_date?->toDateString(),
                    'comments' => $comments,
                    'decided_at' => now()->toDateTimeString(),
                ], reason: $comments);

                // Every surviving decision is sealed server-side: the hash is
                // recomputed from the canonical payload, so it is tamper-
                // evident regardless of what the client attested. The client's
                // ceremony value (required on approvals, optional otherwise)
                // is kept as the opaque reference. Blocked (rolled-back)
                // decisions seal nothing — there is no decision to seal.
                $this->signatures->record($action, $actor, $request, array_filter([
                    'signature_value' => $signature['signature_hash'] ?? null,
                    'signature_type' => $signature['signature_type'] ?? null,
                    'signature_image' => $signature['signature_image'] ?? null,
                    'ip' => $signature['ip'] ?? null,
                    'user_agent' => $signature['user_agent'] ?? null,
                ], fn ($v) => $v !== null));
            }

            return $outcome;
        });
    }

    /**
     * Is the actor an active delegate of one of the given approvers, for the
     * assignment's role? Scoping to `$delegatorIds` is what keeps a
     * same-role delegation from authorising a different approver's step.
     *
     * @param  list<string|null>  $delegatorIds
     */
    protected function activeDelegationExists(Employee $actor, array $delegatorIds, string $assignmentRoleId): bool
    {
        $delegatorIds = array_values(array_filter($delegatorIds));

        if ($delegatorIds === []) {
            return false;
        }

        return Delegation::where('delegate_employee_id', $actor->id)
            ->whereIn('delegator_employee_id', $delegatorIds)
            ->where(function ($q) use ($assignmentRoleId) {
                $q->where('role_id', $assignmentRoleId)
                    ->orWhere('approval_role_id', $assignmentRoleId);
            })
            ->where('active', true)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->exists();
    }

    protected function advanceAfterDecision(LeaveRequest $request, Employee $actor, string $decision, ?string $comments, WorkflowAssignment $done): array
    {
        $employee = $request->employee;

        if ($decision === 'rejected') {
            $request->assignments()->whereIn('status', ['pending', 'queued'])->update(['status' => 'skipped']);
            $request->update(['status' => 'rejected', 'decided_at' => now(), 'current_owner_id' => null]);
            $this->caseService->closeDockets($request);
            event(new LeaveRequestRejected($request, $actor, $comments));

            return ['toast' => "{$request->ref} rejected. Remaining steps skipped; the requester has been notified.", 'blocked_by_handover' => false];
        }

        if ($decision === 'returned') {
            $request->assignments()->whereIn('status', ['pending', 'queued'])->update(['status' => 'skipped']);
            $request->update(['status' => 'returned_for_correction', 'current_owner_id' => null]);
            event(new LeaveRequestReturned($request, $comments));

            return ['toast' => "{$request->ref} returned for correction. The requester can resubmit.", 'blocked_by_handover' => false];
        }

        // approved path
        $next = $request->assignments()->whereIn('status', ['queued', 'pending'])->orderBy('id')->first();
        if ($next) {
            $next->update(['status' => 'pending']);
            $request->update(['current_owner_id' => $next->assigned_employee_id]);
            $this->notifications->notify($next->assigned_employee_id, 'submitted', "{$request->ref} advanced to your step ({$next->assigned_role_id}).", relatedEntityType: 'workflow_assignment', relatedEntityId: $next->id);

            return ['toast' => "Step approved — chain advanced to {$next->assignee->full_name}.", 'blocked_by_handover' => false];
        }

        // Final step: blueprint Part 3 handover gate. The gate PAUSES the
        // request (status stays under_review, current_owner null) and rolls
        // the just-consumed step back to pending so the same authority can
        // re-approve once handovers are accepted — it never counts as a
        // decision and never touches the audit chain.
        $blockers = $this->caseService->missingHandovers($request);
        if (! $blockers['empty']) {
            ApprovalAction::where('assignment_id', $done->id)->orderByDesc('acted_at')->first()?->delete();
            $done->update(['status' => 'pending', 'decided_at' => null]);
            $request->update(['current_owner_id' => null]);
            $refs = implode(', ', $blockers['refs']);
            $this->notifications->notify($employee->id, 'handover_proposed', "{$request->ref} haiwezi kukamilika hadi kesi zako wazi zikabidhiwe: {$refs}. Fungua ombi hili na ubofye \"Kabidhi kesi zako\" ili kumkabidhi mwenzako aziweze; mwombaji atapokea taarifa na kuikamilisha, kisha mwidhinishaji wa mwisho anaweza kutoa likizo yako. / Cannot complete until your open cases are handed over and accepted ({$refs}). Open the request and use \"Hand over my cases\".", relatedEntityType: 'leave_request', relatedEntityId: $request->id);

            return ['toast' => 'Final approval deferred (idhini ya mwisho imeahirishwa) — the requester must propose a handover of every open case and the recipient must accept it first. / Mwombaji lazima awaachie kesi zake wazi na mpokeaji azikubali kwanza.', 'blocked_by_handover' => true];
        }

        $request->update(['status' => 'approved', 'decided_at' => now(), 'current_owner_id' => $employee->id]);
        $this->caseService->returnDockets($request);

        // Accountant hand-off: when the approved leave is marked as paid, notify
        // the accounting holder so they can open and settle the payment case.
        if ($request->is_paid) {
            $accountingHolder = $this->resolver->standingHolder($employee, 'ACCOUNTING', exclude: $employee->id, scopeType: 'office_wide');
            if ($accountingHolder) {
                $this->notifications->notify(
                    $accountingHolder->id,
                    'payment_handoff',
                    "{$request->ref} imeidhinishwa na italipwa. Fungua kesi ya malipo kwa mtumishi {$employee->full_name}.",
                    relatedEntityType: 'leave_request',
                    relatedEntityId: $request->id,
                );
            }
        }

        // Phase 3: open the return-to-work case, freezing the expected return
        // date and its calculation snapshot. Idempotent per request.
        $this->returns->openForRequest($request, $actor->user_id);

        event(new LeaveRequestApproved($request));

        // Blueprint §4.2: emergency leave approved → privacy-safe SMS to the
        // zonal head of the requester's zone (CHANNEL sms, template enforced).
        if ($request->leaveType?->code === 'EMERGENCY' || $request->is_emergency_exception) {
            $zonalHead = $this->resolver->standingHolder($employee, 'ZONAL_HEAD', exclude: $employee->id);
            if ($zonalHead) {
                $this->notifications->notifyLeaveDates($employee, $request->start_date->toDateString(), $request->end_date->toDateString(), sms: true);
                $this->notifications->notify(
                    $zonalHead->id,
                    'approved',
                    sprintf("%s will be on approved leave from %s to %s.\nPlease adjust court schedule as needed. — DPP Leave System",
                        $employee->full_name,
                        Carbon::parse($request->start_date)->format('M j, Y'),
                        Carbon::parse($request->end_date)->format('M j, Y'),
                    ),
                    'sms',
                    'leave_request',
                    $request->id,
                    privacySafe: true,
                );
            }
        }

        return ['toast' => "{$request->ref} fully approved.", 'blocked_by_handover' => false];
    }

    public function withdraw(LeaveRequest $request, ?string $actorUserId = null): LeaveRequest
    {
        DB::transaction(function () use ($request, $actorUserId) {
            $request->assignments()->whereIn('status', ['pending', 'queued'])->update(['status' => 'skipped']);
            $request->update(['status' => 'withdrawn', 'current_owner_id' => null]);
            $this->audit->append('leave_request', (string) $request->id, 'withdraw', $actorUserId, ['ref' => $request->ref]);
        });

        return $request->fresh();
    }

    /**
     * Hard-delete a request that never entered approval. A draft carries no
     * chain, no decisions and no calendar rows — there is no history worth
     * keeping, so the applicant can make it disappear entirely (the audit
     * trail still records who removed which ref and why it existed).
     *
     * Callers must have verified ownership and status === 'draft'; the
     * status is re-checked here because a hard delete can never be undone.
     *
     * @throws ValidationException
     */
    public function destroyDraft(LeaveRequest $request, ?string $actorUserId = null): void
    {
        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Ni maombi ya rasimu pekee yanayoweza kufutwa. Maombi yaliyowasilishwa yanabaki kwenye kumbukumbu za ukaguzi.']);
        }

        DB::transaction(function () use ($request, $actorUserId) {
            $this->audit->append('leave_request', (string) $request->id, 'draft_delete', $actorUserId, [
                'ref' => $request->ref,
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'start_date' => $request->start_date?->toDateString(),
                'end_date' => $request->end_date?->toDateString(),
                'reason' => $request->reason,
            ]);

            foreach ($request->documents()->get() as $document) {
                if ($document->file_ref) {
                    Storage::disk('local')->delete($document->file_ref);
                }
            }

            // Dates and documents also cascade at the DB level; assignments
            // cannot exist on a draft — all three are deleted explicitly so
            // the removal is total rather than dependent on FK behaviour.
            $request->requestDates()->delete();
            $request->documents()->delete();
            $request->assignments()->delete();
            $request->delete();
        });
    }

    /** Populate leave_request_dates (powers absence calendar + reports). */
    protected function storeDates(LeaveRequest $request): void
    {
        if (! $request->end_date) {
            return;
        }
        $request->requestDates()->delete();
        $days = Carbon::parse($request->start_date->toDateString())->diffInDays(Carbon::parse($request->end_date->toDateString())) + 1;
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::parse($request->start_date->toDateString())->addDays($i)->toDateString();
            $fraction = $request->is_half_day && $i === $days - 1 ? 0.5 : 1.0;
            LeaveRequestDate::create(['request_id' => $request->id, 'the_date' => $date, 'day_fraction' => $fraction]);
        }
    }

    /**
     * Approver-provided return date must respect the policy maximum and must
     * not create overlapping approved leave for the same employee.
     */
    protected function validateApprovedPeriod(LeaveRequest $request, string $endDate): void
    {
        $start = $request->start_date->toDateString();
        if (Carbon::parse($endDate)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages(['end_date' => 'Tarehe ya kurudi haiwezi kuwa kabla ya tarehe ya kuanza likizo.']);
        }

        $policy = $this->policy->policyEffectiveAt($request->leave_type_id, $start);
        $days = $this->policy->dayCount($start, $endDate);
        if ($policy && (float) $policy->max_days_per_request > 0 && $days > (float) $policy->max_days_per_request) {
            throw ValidationException::withMessages(['end_date' => "Tarehe ya kurudi inatoa siku {$days}, ikizidi kiwango cha juu cha siku {$policy->max_days_per_request} kwa aina hii ya likizo."]);
        }

        // Annual leave cannot be granted beyond the shared service-year bank.
        // Other approved leave types also consume that bank, but may still be
        // approved when the bank is exhausted (the annual balance bottoms at 0).
        $annualType = LeaveType::where('code', 'ANNUAL')->first();
        if ($annualType && $request->leave_type_id === $annualType->id) {
            $annualBalance = $this->policy->balanceFor($request->employee_id, $annualType->id);
            if ($days > $annualBalance['remaining']) {
                throw ValidationException::withMessages([
                    'end_date' => "Muda huu unazidi salio la pamoja la likizo ya mwaka la siku {$annualBalance['remaining']} baada ya likizo nyingine zilizochukuliwa mwaka huu wa utumishi.",
                ]);
            }
        }

        $overlap = LeaveRequest::query()
            ->where('employee_id', $request->employee_id)
            ->where('id', '!=', $request->id)
            ->whereIn('status', ['approved', 'under_review'])
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($start) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $start);
            })
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['end_date' => 'Tarehe hii ya kurudi inagongana na likizo nyingine iliyoidhinishwa kwenye kalenda (This return date overlaps another approved leave on the calendar).']);
        }
    }

    /**
     * Hard validation on submit (soft warnings like staffing tips are NOT
     * blocking — consistent with "no system auto-decision").
     */
    protected function validateForSubmission(LeaveRequest $request, Employee $employee): array
    {
        $errors = [];
        $elig = app(LeaveEligibilityService::class)->check($employee, $request->leave_type_id, $request->start_date->toDateString(), $request->end_date?->toDateString(), [
            'is_emergency_exception' => (bool) $request->is_emergency_exception,
        ]);
        if (! $elig['ok']) {
            $errors['eligibility'] = $elig['blockers'];
        }
        if ($request->end_date && $request->start_date->gt($request->end_date)) {
            $errors['dates'] = 'Tarehe ya mwisho lazima iwe sawa na au baada ya tarehe ya kuanza (End date must be on or after the start date).';
        }

        return $errors;
    }

    public function nextRef(): string
    {
        $year = now()->year;
        $prefix = 'LVE-'.$year.'-';
        $max = LeaveRequest::query()
            ->where('ref', 'LIKE', $prefix.'%')
            ->max('ref');
        $num = 0;
        if ($max) {
            $num = (int) str_replace($prefix, '', $max);
        }

        return $prefix.str_pad((string) ($num + 1), 4, '0', STR_PAD_LEFT);
    }
}
