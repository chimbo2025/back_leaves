<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveReturn;
use App\Services\LateReturnService;
use App\Services\ReturnToWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Return-to-work endpoints (Phase 3).
 *
 *   POST /leave-requests/{leaveRequest}/return   report the actual return
 *   GET  /leave-requests/{leaveRequest}/return   read the return case
 *   POST /leave-returns/{leaveReturn}/explanation  supply/amend the explanation
 *   POST /leave-returns/{leaveReturn}/review       decide a late return
 *   POST /leave-returns/{leaveReturn}/close        close a decided case
 */
class LeaveReturnController extends Controller
{
    public function __construct(
        protected ReturnToWorkService $returns,
        protected LateReturnService $late,
    ) {}

    public function report(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $request->user();

        // Lazy-open for an already-approved historical request that predates the
        // feature; the service is idempotent, so this can never mint a second case.
        $return = LeaveReturn::where('leave_request_id', $leaveRequest->id)->first()
            ?? $this->returns->openForRequest($leaveRequest, $user->id);

        if (! $return) {
            return response()->json(['message' => 'No return-to-work case exists for this request.'], 404);
        }

        $this->authorize('report', $return);

        $data = $request->validate([
            'actual_return_date' => ['required', 'date'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'supporting_document_id' => ['nullable', 'integer', 'exists:leave_documents,id'],
        ]);

        $return = $this->returns->reportReturn($user, $return, $data);

        return response()->json(['data' => $this->resource($return)]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $return = LeaveReturn::where('leave_request_id', $leaveRequest->id)->first();

        if (! $return) {
            return response()->json(['data' => null]);
        }

        $this->authorize('view', $return);

        return response()->json(['data' => $this->resource($return)]);
    }

    public function explanation(Request $request, LeaveReturn $leaveReturn): JsonResponse
    {
        $this->authorize('explanation', $leaveReturn);

        $data = $request->validate([
            'explanation' => ['required', 'string', 'max:2000'],
        ]);

        $return = $this->returns->submitExplanation($request->user(), $leaveReturn, $data['explanation']);

        return response()->json(['data' => $this->resource($return)]);
    }

    public function review(Request $request, LeaveReturn $leaveReturn): JsonResponse
    {
        $this->authorize('review', $leaveReturn);

        // excused_days and apply_deduction are the reviewer's intent; the
        // unauthorised and deducted days are always derived by the service.
        $data = $request->validate([
            'excused_days' => ['nullable', 'integer', 'min:0'],
            'apply_deduction' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $outcome = $this->late->review(
            $request->user(),
            $leaveReturn,
            $data,
            $data['comment'] ?? null,
        );

        return response()->json([
            'data' => $this->resource($outcome['return']),
            'balance_transaction' => $outcome['transaction'],
            'message' => $outcome['transaction']
                ? 'Late return reviewed; deduction posted to the ledger.'
                : 'Late return reviewed.',
        ]);
    }

    public function close(Request $request, LeaveReturn $leaveReturn): JsonResponse
    {
        $this->authorize('close', $leaveReturn);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $return = $this->late->close($request->user(), $leaveReturn, $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($return)]);
    }

    protected function resource(LeaveReturn $return): array
    {
        $return->loadMissing(['leaveRequest']);

        return [
            ...$return->only(
                'id', 'leave_request_id', 'employee_id', 'expected_return_date', 'actual_return_date',
                'status', 'reported_by', 'reported_at', 'reviewed_by', 'reviewed_at', 'decision_reason',
                'explanation', 'explanation_status', 'supporting_document_id',
                'late_days', 'excused_days', 'unauthorised_days', 'deducted_days', 'policy_snapshot',
                'closed_by', 'closed_at', 'closure_reason',
            ),
            'leave_request' => $return->leaveRequest?->only('id', 'ref', 'leave_type_id', 'status', 'end_date'),
        ];
    }
}
