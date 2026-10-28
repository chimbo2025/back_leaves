<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeavePlan;
use App\Services\AccessScopeService;
use App\Services\LeavePlanService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeavePlanController extends Controller
{
    public function __construct(
        protected LeavePlanService $service,
        protected PermissionService $permissions,
        protected AccessScopeService $accessScope,
    ) {}

    /**
     * Own plans by default; `scope=all` opens the manager window, which is then
     * constrained by the caller's leave_plans zone/department scope. The scope
     * hint never overrides authorization — a caller without a plans read code
     * that reaches an office is answered 403, not with a nationwide listing.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = LeavePlan::with(['employee', 'leaveType', 'policy']);

        if ($request->input('scope') === 'all') {
            $hasViewScope = $this->permissions->can($user, 'leave_plans', 'view_cross_zone')
                || $this->permissions->can($user, 'leave_plans', 'view_zone')
                || $this->permissions->can($user, 'leave_plans', 'view_department');

            if (! $hasViewScope) {
                return response()->json(['message' => 'Missing permission: leave_plans.view_department/view_zone/view_cross_zone.'], 403);
            }

            $this->accessScope->applyLeaveRequestScope($query, $user, 'leave_plans');
        } else {
            $query->where('employee_id', $user->employee?->id);
        }

        return response()->json(['data' => $query->latest()->paginate((int) $request->input('per_page', 20))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LeavePlan::class);

        $data = $request->validate([
            'leave_type_id' => ['required', 'string', 'exists:leave_types,id'],
            'cycle_start' => ['nullable', 'date'],
            'cycle_end' => ['nullable', 'date', 'after_or_equal:cycle_start'],
            'leave_policy_id' => ['nullable', 'string', 'exists:leave_policies,id'],
        ]);

        $plan = $this->service->createDraft($request->user()->employee->id, $data);

        return response()->json(['data' => $plan->load(['employee', 'leaveType', 'policy'])], 201);
    }

    public function show(Request $request, LeavePlan $leavePlan): JsonResponse
    {
        $this->authorize('view', $leavePlan);

        return response()->json(['data' => $leavePlan->load(['employee', 'leaveType', 'policy', 'options'])]);
    }

    public function propose(Request $request, LeavePlan $leavePlan): JsonResponse
    {
        $this->authorize('propose', $leavePlan);

        $data = $request->validate(['desired_days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        $result = $this->service->propose($leavePlan, $data['desired_days'] ?? null);

        return response()->json([
            'data' => $result['plan'],
            'options' => $result['options'],
            'explanation' => $result['explanation'],
        ]);
    }

    public function select(Request $request, LeavePlan $leavePlan): JsonResponse
    {
        $this->authorize('select', $leavePlan);

        $data = $request->validate(['option_number' => ['required', 'integer', 'min:1']]);

        $plan = $this->service->select($leavePlan, $request->user()->employee, (int) $data['option_number']);

        return response()->json([
            'data' => $plan->load(['employee', 'leaveType', 'policy', 'options']),
            'message' => 'Option selected. The plan is now marked employee_selected.',
        ]);
    }

    /** Manager confirmation: confirmed | manager_review | rejected. */
    public function confirm(Request $request, LeavePlan $leavePlan): JsonResponse
    {
        $this->authorize('confirm', $leavePlan);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:confirmed,manager_review,rejected'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = $this->service->confirm($leavePlan, $request->user()->employee, $data['decision'], $data['comment'] ?? null, $request->user()->id);

        return response()->json(['data' => $plan->load(['employee', 'leaveType', 'policy', 'options'])]);
    }

    public function convert(Request $request, LeavePlan $leavePlan): JsonResponse
    {
        $this->authorize('convert', $leavePlan);

        $outcome = $this->service->convert($leavePlan, $request->user()->employee, $request->user()->id);

        return response()->json([
            'data' => $outcome['plan']->load(['employee', 'leaveType', 'policy', 'options']),
            'request' => $outcome['request']->load(['employee', 'leaveType', 'policy', 'assignments']),
            'created' => $outcome['created'],
        ], $outcome['created'] ? 201 : 200);
    }

    public function cancel(Request $request, LeavePlan $leavePlan): JsonResponse
    {
        $this->authorize('cancel', $leavePlan);

        $plan = $this->service->cancel($leavePlan, $request->user()->id);

        return response()->json(['data' => $plan->load(['employee', 'leaveType', 'policy'])]);
    }

    /** Manager calendar: confirmed/upcoming plans within scope, no sensitive fields. */
    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();

        $hasViewScope = $this->permissions->can($user, 'leave_plans', 'view_cross_zone')
            || $this->permissions->can($user, 'leave_plans', 'view_zone')
            || $this->permissions->can($user, 'leave_plans', 'view_department');

        if (! $hasViewScope) {
            return response()->json(['message' => 'Missing permission: leave_plans view scope.'], 403);
        }

        $query = LeavePlan::query()
            ->with('employee')
            ->whereIn('status', ['confirmed', 'converted_to_request'])
            ->whereNotNull('confirmed_start_date')
            ->whereNotNull('confirmed_end_date');

        $this->accessScope->applyLeaveRequestScope($query, $user, 'leave_plans');

        return response()->json([
            'data' => $query->orderBy('confirmed_start_date')->get()->map(function (LeavePlan $plan) {
                return [
                    'employee_id' => $plan->employee_id,
                    'employee_name' => $plan->employee?->full_name,
                    'department_id' => $plan->employee?->department_id,
                    'zone_id' => $plan->employee?->zone_id,
                    'leave_type_id' => $plan->leave_type_id,
                    'status' => $plan->status,
                    'start_date' => $plan->confirmed_start_date?->toDateString(),
                    'end_date' => $plan->confirmed_end_date?->toDateString(),
                ];
            }),
        ]);
    }
}
