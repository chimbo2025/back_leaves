<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaseHandover;
use App\Models\LeaveRequest;
use App\Models\ProsecutionCase;
use App\Services\CaseHandoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Court calendar + docket continuity. Handover gates live here so the final
 * approval path (LeaveRequestService) and the explicit cases UI agree.
 */
class CaseController extends Controller
{
    public function __construct(protected CaseHandoverService $service) {}

    /** Court cases assigned to the authenticated prosecutor. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ProsecutionCase::with(['prosecutor', 'owner'])
                ->where('assigned_prosecutor_id', $request->user()->employee?->id)
                ->orderByDesc('next_hearing_date')
                ->get(),
        ]);
    }

    public function canFinalApprove(Request $request): JsonResponse
    {
        return response()->json(['can_final_approve' => $this->service->canFinalApprove($request->user()->employee)]);
    }

    /**
     * Handovers awaiting the authenticated employee's acceptance — every one,
     * across all requests, not just the ones they can open. This is what makes
     * "someone else hands you the case" actionable: the recipient gets an
     * inbox instead of a silently empty case list.
     */
    public function pendingMine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }

        $pending = CaseHandover::with(['case', 'request.employee', 'from'])
            ->where('to_employee_id', $employee->id)
            ->where('status', 'proposed')
            ->orderByDesc('proposed_at')
            ->get()
            ->map(fn (CaseHandover $h) => [
                'id' => $h->id,
                'case_id' => $h->case_id,
                'case_ref' => $h->case?->ref,
                'case_kind' => $h->case?->kind,
                'next_hearing_date' => $h->case?->next_hearing_date?->toDateString(),
                'request_id' => $h->request_id,
                'request_ref' => $h->request?->ref,
                'requester_name' => $h->request?->employee?->full_name,
                'from_name' => $h->from?->full_name,
                'reason' => $h->notes,
                'proposed_at' => $h->proposed_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $pending]);
    }

    /** Handovers for a leave request (proposed/awaiting/complete). */
    public function handovers(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        $missing = $this->service->missingHandovers($leaveRequest);

        // Full rows for the cases still blocking final approval, so the
        // requester can hand them over from the request page.
        $missing['cases'] = $this->service->openCasesMissingHandover($leaveRequest)
            ->map(fn ($case) => [
                'id' => $case->id,
                'ref' => $case->ref,
                'next_hearing_date' => $case->next_hearing_date?->toDateString(),
            ])->values();

        return response()->json([
            'data' => CaseHandover::with(['case', 'from', 'to', 'reviewer'])
                ->where('request_id', $leaveRequest->id)
                ->get(),
            'missing' => $missing,
        ]);
    }

    /**
     * Propose a handover of the requester's active court cases.
     *
     * This used to authorise with `view` on the request. `view` is a READ
     * predicate, and reusing it for a WRITE meant that anyone who could see a
     * request could reassign a live court case to any colleague they named -
     * a zone-scoped supervisor could strip cases from another office purely by
     * being able to read the request. The sibling actions (accept, reject,
     * complete) all authorise against the handover itself; this one now does too.
     */
    public function propose(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('proposeHandover', $leaveRequest);

        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Hakuna rekodi ya mtumishi iliyounganishwa na akaunti hii.'], 403);
        }

        $request->validate([
            'to_employee_id' => ['required', 'exists:employees,id'],
            'case_ids' => ['sometimes', 'array'],
            'case_ids.*' => ['integer', 'exists:cases,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $created = $this->service->propose(
            $leaveRequest,
            $employee->id,
            $request->to_employee_id,
            $request->input('case_ids'),
            $request->input('reason'),
        );

        return response()->json(['data' => $created->load(['from', 'to', 'case'])], 201);
    }

    public function accept(Request $request, CaseHandover $handover): JsonResponse
    {
        $this->authorize('accept', $handover);

        $accepted = $this->service->accept($handover, $request->input('notes'));
        $this->notifyHandoverOutcome($accepted, 'accepted');

        return response()->json(['data' => $accepted->load(['from', 'to', 'case'])]);
    }

    public function reject(Request $request, CaseHandover $handover): JsonResponse
    {
        $this->authorize('reject', $handover);

        $rejected = $this->service->reject($handover, $request->input('notes'));
        $this->notifyHandoverOutcome($rejected, 'rejected');

        return response()->json(['data' => $rejected->load(['from', 'to', 'case'])]);
    }

    /**
     * Close the loop for everyone blocked on the handover: the requester
     * (whose leave cannot complete) and — on acceptance — whoever holds the
     * request's current pending approval step.
     */
    protected function notifyHandoverOutcome(CaseHandover $handover, string $outcome): void
    {
        $notifications = app(\App\Services\NotificationService::class);
        $handover->loadMissing(['case', 'request.employee', 'to']);
        $leaveRequest = $handover->request;
        if (! $leaveRequest) {
            return;
        }

        $caseRef = $handover->case?->ref ?? 'kazi';
        $toName = $handover->to?->full_name ?? 'Mwenzako';
        $event = $outcome === 'accepted' ? 'handover_accepted' : 'handover_rejected';

        if ($leaveRequest->employee_id) {
            $notifications->notify(
                $leaveRequest->employee_id,
                $event,
                $outcome === 'accepted'
                    ? "{$leaveRequest->ref}: {$toName} amepokea kesi {$caseRef} — mwidhinishaji wa mwisho sasa anaweza kutoa likizo. / {$toName} has accepted case {$caseRef}; the final approver can now complete your leave."
                    : "{$leaveRequest->ref}: {$toName} amekataa kupokea kesi {$caseRef}. Ombi limesitishwa — kabidhi kesi kwa mtu mwingine. / {$toName} declined case {$caseRef}; please hand it over to someone else.",
                'in_system',
                'leave_request',
                $leaveRequest->id,
            );
        }

        if ($outcome === 'accepted') {
            $next = \App\Models\WorkflowAssignment::where('request_id', $leaveRequest->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->first();

            if ($next?->assigned_employee_id) {
                $notifications->notify(
                    $next->assigned_employee_id,
                    $event,
                    "{$leaveRequest->ref}: kesi zimekamilisha ukabidhi — unaweza kuidhinisha sasa. / Outstanding case handovers are accepted; you can now decide this request.",
                    'in_system',
                    'leave_request',
                    $leaveRequest->id,
                );
            }
        }
    }

    /** Supervisor/zonal capacity review sign-off. */
    public function complete(Request $request, CaseHandover $handover): JsonResponse
    {
        $this->authorize('complete', $handover);

        $completed = $this->service->complete($handover, $request->user()->employee?->id);

        return response()->json(['data' => $completed->load(['from', 'to', 'case', 'reviewer'])]);
    }
}
