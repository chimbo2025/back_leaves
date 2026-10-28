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
            throw ValidationException::withMessages(['authorization' => 'You can only edit your own requests.']);
        }
        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Only draft requests can be edited (current: {$request->status})."]);
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
            throw ValidationException::withMessages(['leave_type_id' => 'No active policy exists for this leave type.']);
        }

        DB::transaction(function () use ($request, $employee, $policy, $actorUserId) {
            $elig = app(LeaveEligibilityService::class)->check($employee, $request->leave_type_id, $request->start_date->toDateString(), $request->end_date?->toDateString(), [
                'is_emergency_exception' => (bool) $request->is_emergency_exception,
            ]);
            $request->update([
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
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
                throw ValidationException::withMessages(['workflow' => 'No approver could be resolved for this request. Contact HR.']);
            }
            $request->assignments()->delete();
            foreach ($chain as $entry) {
                if (! $entry['assignee_id']) {
                    continue;
                }
                WorkflowAssignment::create([
                    'request_id' => $request->id,
                    'step_id' => $entry['step_id'],
                    'assigned_employee_id' => $entry['assignee_id'],
                    'assigned_role_id' => $entry['role'],
                    'status' => 'pending',
                    'due_at' => $entry['due_at'],
                ]);
            }

            $first = $request->assignments()->where('status', 'pending')->orderBy('id')->first();
            $request->update([
                'status' => $first ? 'under_review' : 'submitted',
                'current_owner_id' => $first?->assigned_employee_id,
            ]);

            if ($first) {
                event(new LeaveRequestSubmitted($request, $first->assignee));
            }
            foreach ($request->assignments->where('assigned_employee_id', '!=', $first?->assigned_employee_id) as $wa) {
                $this->notifications->notify($wa->assigned_employee_id, 'submitted', "A step for {$request->ref} has been assigned to you.", 'in_system', 'workflow_assignment', $wa->id);
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
            throw ValidationException::withMessages(['status' => "Only returned or withdrawn requests can be resubmitted (current: {$request->status})."]);
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
            $request->update([
                'policy_id' => $policy?->id,
                'policy_version' => $policy?->version,
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
            // Keep approved steps; archive leftover skipped/pending, then re-create.
            $request->assignments()->where('status', '!=', 'done')->update(['status' => 'skipped']);
            foreach ($chain as $entry) {
                if (! $entry['assignee_id']) {
                    continue;
                }
                $already = WorkflowAssignment::where('request_id', $request->id)
                    ->where('step_id', $entry['step_id'])
                    ->where('status', 'done')
                    ->exists();
                if ($already) {
                    continue;
                }
                $request->assignments()->create([
                    'step_id' => $entry['step_id'],
                    'assigned_employee_id' => $entry['assignee_id'],
                    'assigned_role_id' => $entry['role'],
                    'status' => 'pending',
                    'due_at' => $entry['due_at'],
                ]);
            }

            $first = $request->assignments()->where('status', 'pending')->orderBy('id')->first();
            $request->update(['status' => 'under_review', 'current_owner_id' => $first?->assigned_employee_id]);
            $this->audit->append('leave_request', (string) $request->id, 'resubmit', $actorUserId, ['ref' => $request->ref]);
        });

        return $request->fresh(['assignments', 'employee', 'leaveType', 'policy']);
    }

    /**
     * @return array{toast: string, blocked_by_handover: bool}
     */
    /**
     * @param  array{signature_hash?:?string,signature_type?:?string,ip?:?string,user_agent?:?string}  $signature
     */
    public function decide(LeaveRequest $request, Employee $actor, string $decision, ?string $comments = null, ?string $endDate = null, array $signature = []): array
    {
        if (! in_array($decision, ['approved', 'rejected', 'returned'], true)) {
            throw ValidationException::withMessages(['decision' => "Invalid decision '{$decision}'."]);
        }

        $pending = $request->assignments()
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        if ($decision === 'approved' && $endDate) {
            $this->validateApprovedPeriod($request, $endDate);
        }

        $current = $pending->first();

        // Only the head of the chain is decidable. Matching any pending
        // assignment the actor happens to hold let an approver jump the queue:
        // with steps [ZONAL_HEAD -> e8, HR -> e1], e1 could approve the HR step
        // while the regional sign-off in front of it was still pending, so the
        // request skipped a reviewer entirely. This ownership check runs first
        // so a stranger still hears "not assigned to you" (422), not a
        // permission error.
        if (! $current || $current->assigned_employee_id !== $actor->id) {
            $hasPending = $pending->isNotEmpty();
            throw ValidationException::withMessages([
                'authorization' => $hasPending
                    ? 'This step is not assigned to you — you cannot decide it.'
                    : 'There are no pending steps to decide.',
            ]);
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
        $delegated = Delegation::where('delegate_employee_id', $actor->id)
            ->where('role_id', $current->assigned_role_id)
            ->where('active', true)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->exists();

        if (! $delegated) {
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
                throw new AuthorizationException("Missing permission: requests.{$need}.");
            }
        }

        $mine = $current;
        $pendingLeft = $pending->slice(1);

        $isFinalStep = $pendingLeft->isEmpty();

        // The return date is decided by the approver: required to finalize an
        // approval, optional (but usable) on intermediate steps.
        if ($decision === 'approved' && $isFinalStep && ! $endDate && $request->end_date === null) {
            throw ValidationException::withMessages(['end_date' => 'Set the return date (date of resumption) before approving this leave.']);
        }

        return DB::transaction(function () use ($request, $actor, $decision, $comments, $endDate, $mine, $signature) {
            $mine->update(['status' => 'done', 'decided_at' => now()]);
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
                $this->audit->append('leave_request', (string) $request->id, $decision, $actor->user_id, [
                    'ref' => $request->ref,
                    'step_role' => $mine->assigned_role_id,
                    'decision' => $decision,
                    'return_date' => $request->end_date?->toDateString(),
                    'comments' => $comments,
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
                    'ip' => $signature['ip'] ?? null,
                    'user_agent' => $signature['user_agent'] ?? null,
                ], fn ($v) => $v !== null));
            }

            return $outcome;
        });
    }

    protected function advanceAfterDecision(LeaveRequest $request, Employee $actor, string $decision, ?string $comments, WorkflowAssignment $done): array
    {
        $employee = $request->employee;

        if ($decision === 'rejected') {
            $request->assignments()->where('status', 'pending')->update(['status' => 'skipped']);
            $request->update(['status' => 'rejected', 'decided_at' => now(), 'current_owner_id' => null]);
            $this->caseService->closeDockets($request);
            event(new LeaveRequestRejected($request, $actor, $comments));

            return ['toast' => "{$request->ref} rejected. Remaining steps skipped; the requester has been notified.", 'blocked_by_handover' => false];
        }

        if ($decision === 'returned') {
            $request->assignments()->where('status', 'pending')->update(['status' => 'skipped']);
            $request->update(['status' => 'returned_for_correction', 'current_owner_id' => null]);
            event(new LeaveRequestReturned($request, $comments));

            return ['toast' => "{$request->ref} returned for correction. The requester can resubmit.", 'blocked_by_handover' => false];
        }

        // approved path
        $next = $request->assignments()->where('status', 'pending')->orderBy('id')->first();
        if ($next) {
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
            $this->notifications->notify($employee->id, 'handover_proposed', "{$request->ref} cannot complete until your open cases are handed over: ".implode(', ', $blockers['refs']), relatedEntityType: 'leave_request', relatedEntityId: $request->id);

            return ['toast' => 'Final approval deferred — outstanding case handovers must be accepted first.', 'blocked_by_handover' => true];
        }

        $request->update(['status' => 'approved', 'decided_at' => now(), 'current_owner_id' => $employee->id]);
        $this->caseService->returnDockets($request);

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
            $request->assignments()->where('status', 'pending')->update(['status' => 'skipped']);
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
            throw ValidationException::withMessages(['status' => 'Only draft requests can be deleted. Submitted requests are kept in the audit trail.']);
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
            throw ValidationException::withMessages(['end_date' => 'Return date cannot be before the leave start date.']);
        }

        $policy = $this->policy->policyEffectiveAt($request->leave_type_id, $start);
        $days = $this->policy->dayCount($start, $endDate);
        if ($policy && (float) $policy->max_days_per_request > 0 && $days > (float) $policy->max_days_per_request) {
            throw ValidationException::withMessages(['end_date' => "Return date gives {$days} days, exceeding the {$policy->max_days_per_request}-day maximum for this leave type."]);
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
            throw ValidationException::withMessages(['end_date' => 'This return date overlaps another approved leave on the calendar.']);
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
            $errors['dates'] = 'End date must be on or after the start date.';
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
