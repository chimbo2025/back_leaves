<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveWaiver;
use App\Services\AccessScopeService;
use App\Services\LeaveWaiverService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveWaiverController extends Controller
{
    public function __construct(
        protected LeaveWaiverService $service,
        protected PermissionService $permissions,
        protected AccessScopeService $accessScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveWaiver::class);

        $user = $request->user();
        $query = LeaveWaiver::with(['employee', 'leaveType', 'policy']);

        $isReviewer = $this->permissions->can($user, 'leave_waivers', 'review')
            || $this->permissions->can($user, 'leave_waivers', 'approve')
            || $this->permissions->can($user, 'leave_waivers', 'reject')
            || $this->permissions->can($user, 'leave_waivers', 'view_department')
            || $this->permissions->can($user, 'leave_waivers', 'view_zone')
            || $this->permissions->can($user, 'leave_waivers', 'view_cross_zone');

        // Scope resolution always includes the caller's own rows and never
        // widens beyond an explicit view_cross_zone permission.
        $this->accessScope->applyLeaveRequestScope($query, $user, 'leave_waivers');

        return response()->json([
            'data' => $query->latest()->paginate((int) $request->input('per_page', 20)),
            'reviewer' => $isReviewer,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LeaveWaiver::class);

        $data = $request->validate([
            'leave_type_id' => ['required', 'string', 'exists:leave_types,id'],
            'leave_policy_id' => ['nullable', 'string', 'exists:leave_policies,id'],
            'leave_cycle_start' => ['nullable', 'date'],
            'leave_cycle_end' => ['nullable', 'date', 'after_or_equal:leave_cycle_start'],
            'requested_action' => ['required', 'string', 'in:employee_declined,employee_requested_deferral,employer_deferred,rescheduled'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $waiver = $this->service->createDraft($request->user()->employee->id, $data);

        return response()->json(['data' => $waiver->load(['employee', 'leaveType', 'policy'])], 201);
    }

    public function show(Request $request, LeaveWaiver $leaveWaiver): JsonResponse
    {
        $this->authorize('view', $leaveWaiver);

        return response()->json(['data' => $leaveWaiver->load(['employee', 'leaveType', 'policy'])]);
    }

    public function submit(Request $request, LeaveWaiver $leaveWaiver): JsonResponse
    {
        $this->authorize('submit', $leaveWaiver);

        $waiver = $this->service->submit($leaveWaiver, $request->user()->employee, $request->user()->id);

        return response()->json(['data' => $waiver->load(['employee', 'leaveType', 'policy'])]);
    }

    public function review(Request $request, LeaveWaiver $leaveWaiver): JsonResponse
    {
        $this->authorize('review', $leaveWaiver);

        $waiver = $this->service->review($leaveWaiver, $request->user()->employee, $request->input('comment'), $request->user()->id);

        return response()->json(['data' => $waiver->load(['employee', 'leaveType', 'policy'])]);
    }

    public function approve(Request $request, LeaveWaiver $leaveWaiver): JsonResponse
    {
        $this->authorize('approve', $leaveWaiver);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $outcome = $this->service->approve($leaveWaiver, $request->user()->employee, $data['comment'] ?? null, $request->user()->id);

        return response()->json([
            'data' => $outcome['waiver']->load(['employee', 'leaveType', 'policy']),
            'effect' => $outcome['effect'],
            'balance_transaction' => $outcome['transaction'],
            'message' => $outcome['transaction'] ? 'Waiver approved; balance effect posted to the ledger.' : 'Waiver decision recorded.',
        ]);
    }

    public function reject(Request $request, LeaveWaiver $leaveWaiver): JsonResponse
    {
        $this->authorize('reject', $leaveWaiver);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $waiver = $this->service->reject($leaveWaiver, $request->user()->employee, $data['comment'] ?? null, $request->user()->id);

        return response()->json([
            'data' => $waiver->load(['employee', 'leaveType', 'policy']),
            'effect' => null,
            'balance_transaction' => null,
            'message' => 'Waiver rejected.',
        ]);
    }

    public function cancel(Request $request, LeaveWaiver $leaveWaiver): JsonResponse
    {
        $this->authorize('cancel', $leaveWaiver);

        $waiver = $this->service->cancel($leaveWaiver, $request->user()->employee, $request->user()->id);

        return response()->json(['data' => $waiver->load(['employee', 'leaveType', 'policy'])]);
    }
}
