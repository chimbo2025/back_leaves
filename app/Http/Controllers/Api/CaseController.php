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

    /** Handovers for a leave request (proposed/awaiting/complete). */
    public function handovers(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        return response()->json([
            'data' => CaseHandover::with(['case', 'from', 'to', 'reviewer'])
                ->where('request_id', $leaveRequest->id)
                ->get(),
            'missing' => $this->service->missingHandovers($leaveRequest),
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
            return response()->json(['message' => 'No employee record is linked to this account.'], 403);
        }

        $request->validate([
            'to_employee_id' => ['required', 'exists:employees,id'],
            'case_ids' => ['sometimes', 'array'],
            'case_ids.*' => ['integer', 'exists:cases,id'],
        ]);

        $created = $this->service->propose(
            $leaveRequest,
            $employee->id,
            $request->to_employee_id,
            $request->input('case_ids'),
        );

        return response()->json(['data' => $created->load(['from', 'to', 'case'])], 201);
    }

    public function accept(Request $request, CaseHandover $handover): JsonResponse
    {
        $this->authorize('accept', $handover);

        $accepted = $this->service->accept($handover, $request->input('notes'));

        return response()->json(['data' => $accepted->load(['from', 'to', 'case'])]);
    }

    public function reject(Request $request, CaseHandover $handover): JsonResponse
    {
        $this->authorize('reject', $handover);

        $rejected = $this->service->reject($handover, $request->input('notes'));

        return response()->json(['data' => $rejected->load(['from', 'to', 'case'])]);
    }

    /** Supervisor/zonal capacity review sign-off. */
    public function complete(Request $request, CaseHandover $handover): JsonResponse
    {
        $this->authorize('complete', $handover);

        $completed = $this->service->complete($handover, $request->user()->employee?->id);

        return response()->json(['data' => $completed->load(['from', 'to', 'case', 'reviewer'])]);
    }
}
