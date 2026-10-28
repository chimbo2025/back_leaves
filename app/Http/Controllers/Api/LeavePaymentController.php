<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeavePaymentCase;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\LeavePaymentCaseService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leave-payment endpoints (Phase 5).
 *
 *   GET  /leave-payments                                list (scoped)
 *   POST /leave-payments                                open a payment case
 *   GET  /leave-payments/{leavePaymentCase}             read a case
 *   POST /leave-payments/{leavePaymentCase}/evaluate    re-run eligibility + calculation
 *   POST /leave-payments/{leavePaymentCase}/submit      move to HR review
 *   POST /leave-payments/{leavePaymentCase}/approve     HR approve
 *   POST /leave-payments/{leavePaymentCase}/reject      HR reject
 *   POST /leave-payments/{leavePaymentCase}/send-to-accounting  hand to Accounting
 *   POST /leave-payments/{leavePaymentCase}/receive     Accounting acknowledges
 *   POST /leave-payments/{leavePaymentCase}/paid        Accounting marks settled
 *   POST /leave-payments/{leavePaymentCase}/reject-payment      Accounting refuses
 *   POST /leave-payments/{leavePaymentCase}/cancel      cancel an open case
 *
 * What a caller sees depends on which side of the workflow they are on: HR-side
 * callers (review/approve) receive the salary basis and the snapshots; everyone
 * else — including Accounting — receives the payable facts only. That keeps the
 * eligibility reasoning (which mentions the leave type) out of an accountant's
 * payload, mirroring the notification rule.
 */
class LeavePaymentController extends Controller
{
    public function __construct(
        protected LeavePaymentCaseService $payments,
        protected PermissionService $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeavePaymentCase::class);

        $user = $request->user();

        $query = LeavePaymentCase::query()->with(['employee', 'leaveType']);

        if (! $this->permissions->can($user, 'leave_payments', 'view_cross_zone')) {
            $employeeId = $user->employee?->id;

            // A subject sees their own cases; a scoped reader additionally sees
            // the cases of employees inside their zone/department.
            $scope = app(AccessScopeService::class)->forUser($user, 'leave_payments');

            $query->where(function ($outer) use ($scope, $employeeId) {
                if ($employeeId !== null) {
                    $outer->orWhere('employee_id', $employeeId);
                }

                if ($scope->hasZoneScope()) {
                    $outer->orWhereHas('employee', function ($emp) use ($scope) {
                        $emp->where(function ($inner) use ($scope) {
                            if ($scope->allowedZoneIds !== []) {
                                $inner->orWhereIn('zone_id', $scope->allowedZoneIds);
                            }
                            if ($scope->allowedDepartmentIds !== []) {
                                $inner->orWhereIn('department_id', $scope->allowedDepartmentIds);
                            }
                        });
                    });
                }
            });
        }

        $data = $query->orderByDesc('created_at')->get()
            ->map(fn (LeavePaymentCase $case) => $this->resource($case, $user));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LeavePaymentCase::class);

        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,id'],
            'leave_type_id' => ['required', 'string', 'exists:leave_types,id'],
            'leave_request_id' => ['nullable', 'integer', 'exists:leave_requests,id'],
            'payment_type' => ['nullable', 'string', 'in:leave_encashment,leave_purchase'],
            'salary_basis' => ['nullable', 'string', 'max:255'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'allowances_included' => ['nullable', 'array'],
            'allowances_excluded' => ['nullable', 'array'],
            'days' => ['nullable', 'numeric', 'min:0'],
            'eligible_days' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'loan_outstanding' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $case = $this->payments->create($request->user(), $data);

        return response()->json(['data' => $this->resource($case, $request->user())], 201);
    }

    public function show(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('view', $leavePaymentCase);

        return response()->json(['data' => $this->resource($leavePaymentCase, $request->user())]);
    }

    public function evaluate(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('evaluate', $leavePaymentCase);

        $data = $request->validate([
            'days' => ['nullable', 'numeric', 'min:0'],
            'eligible_days' => ['nullable', 'numeric', 'min:0'],
            'loan_outstanding' => ['nullable', 'boolean'],
        ]);

        $case = $this->payments->evaluate($request->user(), $leavePaymentCase, $data);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function submit(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('submit', $leavePaymentCase);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $case = $this->payments->submitForHrReview($request->user(), $leavePaymentCase, $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function approve(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('approve', $leavePaymentCase);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $case = $this->payments->approve($request->user(), $leavePaymentCase, $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function reject(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('reject', $leavePaymentCase);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->payments->reject($request->user(), $leavePaymentCase, $data['reason'], $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function sendToAccounting(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('sendToAccounting', $leavePaymentCase);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $case = $this->payments->sendToAccounting($request->user(), $leavePaymentCase, $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function receive(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('receive', $leavePaymentCase);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $case = $this->payments->receive($request->user(), $leavePaymentCase, $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function paid(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('process', $leavePaymentCase);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $case = $this->payments->markPaid($request->user(), $leavePaymentCase, $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function rejectPayment(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('process', $leavePaymentCase);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->payments->rejectPayment($request->user(), $leavePaymentCase, $data['reason'], $data['comment'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    public function cancel(Request $request, LeavePaymentCase $leavePaymentCase): JsonResponse
    {
        $this->authorize('cancel', $leavePaymentCase);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $case = $this->payments->cancel($request->user(), $leavePaymentCase, $data['reason'] ?? null);

        return response()->json(['data' => $this->resource($case, $request->user())]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function resource(LeavePaymentCase $case, ?User $user): array
    {
        $case->loadMissing(['employee', 'leaveType']);

        $payload = [
            ...$case->only(
                'id', 'employee_id', 'leave_request_id', 'leave_type_id', 'policy_id',
                'payment_type', 'status', 'eligible_amount', 'calculated_amount', 'currency',
                'created_by', 'submitted_by', 'submitted_at',
                'hr_reviewed_by', 'hr_reviewed_at',
                'sent_to_accounting_by', 'sent_to_accounting_at', 'accounting_notified_at',
                'processing_at', 'processed_by', 'paid_at',
                'rejected_by', 'rejected_at', 'rejection_reason',
                'cancelled_by', 'cancelled_at', 'cancellation_reason',
                'created_at', 'updated_at',
            ),
            'employee' => $case->employee?->only('id', 'full_name', 'department_id', 'zone_id'),
            'leave_type' => $case->leaveType?->only('id', 'code', 'name'),
        ];

        // HR-side callers get the salary basis and the reasoning snapshots;
        // everyone else (including Accounting) gets the payable facts only.
        $hrSide = $user && $this->permissions->canAny($user, 'leave_payments', ['review', 'approve']);

        if ($hrSide) {
            $payload += $case->only(
                'salary_basis', 'salary_amount', 'allowances_included', 'allowances_excluded',
                'calculation_snapshot', 'eligibility_snapshot',
            );
        }

        return $payload;
    }
}
