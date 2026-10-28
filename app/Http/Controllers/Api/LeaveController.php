<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Models\BalanceTransaction;
use App\Models\LeaveBalance;
use App\Models\LeavePaymentCase;
use App\Models\LeavePlan;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveWaiver;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Services\AuditService;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveController extends Controller
{
    public function __construct(protected PolicyService $policy, protected AuditService $audit) {}

    public function leaveTypes(): JsonResponse
    {
        return response()->json(['data' => LeaveType::orderBy('name')->get()]);
    }

    /** Add a leave type from the admin settings console. */
    public function storeLeaveType(StoreLeaveTypeRequest $form): JsonResponse
    {
        $id = $form->input('id') ?: 'lt'.(LeaveType::count() + 1);
        $type = LeaveType::create([
            'id' => $id,
            ...$form->except(['id']),
        ]);
        $this->audit->append('leave_type', $id, 'create', $form->user()->id, [
            'code' => $type->code,
            'name' => $type->name,
        ]);

        return response()->json(['data' => $type], 201);
    }

    /** Update leave-type metadata from the admin settings console. */
    public function updateLeaveType(UpdateLeaveTypeRequest $form, LeaveType $leaveType): JsonResponse
    {
        $leaveType->update($form->validated());
        $this->audit->append('leave_type', (string) $leaveType->id, 'update', $form->user()->id, [
            'code' => $leaveType->code,
            'name' => $leaveType->name,
        ]);

        return response()->json(['data' => $leaveType->refresh()]);
    }

    /**
     * Delete a leave type — only ever one that nothing depends on.
     *
     * The history tables (requests, balance adjustments, plans, waivers)
     * all name their leave type directly; a referenced type is permanent
     * by doctrine, so those references refuse the delete with a count. A
     * free type takes its whole configuration family with it: policy
     * versions, workflow definitions and steps, balances, payment cases.
     */
    public function destroyLeaveType(Request $request, LeaveType $leaveType): JsonResponse
    {
        $refs = [
            'leave request' => LeaveRequest::where('leave_type_id', $leaveType->id)->count(),
            'balance adjustment' => BalanceTransaction::where('leave_type_id', $leaveType->id)->count(),
            'leave plan' => LeavePlan::where('leave_type_id', $leaveType->id)->count(),
            'waiver' => LeaveWaiver::where('leave_type_id', $leaveType->id)->count(),
        ];

        $parts = [];
        foreach ($refs as $label => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$label}".($count === 1 ? '' : 's');
            }
        }
        if ($parts !== []) {
            return response()->json([
                'message' => 'This leave type is referenced by '.implode(', ', $parts).' — history is permanent. It cannot be deleted.',
            ], 422);
        }

        $id = (string) $leaveType->id;
        $name = $leaveType->name;

        DB::transaction(function () use ($leaveType, $id) {
            // Configuration family, removed explicitly so the deletion never
            // depends on foreign-key behaviour: workflow steps before their
            // definitions, then policies, balances and payment cases.
            WorkflowStep::whereIn('workflow_def_id', WorkflowDefinition::where('leave_type_id', $id)->pluck('id'))->delete();
            WorkflowDefinition::where('leave_type_id', $id)->delete();
            LeavePolicy::where('leave_type_id', $id)->delete();
            LeaveBalance::where('leave_type_id', $id)->delete();
            LeavePaymentCase::where('leave_type_id', $id)->delete();

            $leaveType->delete();
        });

        $this->audit->append('leave_type', $id, 'delete', $request->user()->id, [
            'name' => $name,
        ]);

        return response()->json(['message' => 'Leave type deleted, together with its policies and workflow.']);
    }

    public function currentPolicies(): JsonResponse
    {
        $today = now()->toDateString();

        return response()->json([
            'data' => LeavePolicy::with('leaveType')
                ->orderBy('version')
                ->get()
                ->map(fn (LeavePolicy $p) => [
                    'id' => $p->id,
                    'leave_type_id' => $p->leave_type_id,
                    'code' => $p->leaveType?->code,
                    'version' => $p->version,
                    'max_days_per_year' => $p->max_days_per_year,
                    'max_days_per_request' => $p->max_days_per_request,
                    'min_tenure_months' => $p->min_tenure_months,
                    'min_notice_days' => $p->min_notice_days,
                    'requires_documents' => $p->requires_documents,
                    'required_docs' => $p->required_docs,
                    'carry_over_max' => $p->carry_over_max,
                    'allow_half_day' => $p->allow_half_day,
                    'effective_from' => $p->effective_from?->toDateString(),
                    'effective_to' => $p->effective_to?->toDateString(),
                    'is_current' => $p->effective_from?->lte($today)
                        && ($p->effective_to === null || $p->effective_to->gte($today)),
                ]),
        ]);
    }

    public function policyHistory(string $leaveTypeId): JsonResponse
    {
        return response()->json([
            'data' => LeavePolicy::where('leave_type_id', $leaveTypeId)->orderByDesc('version')->get(),
        ]);
    }

    /** Current tenure-derived balance for the authenticated employee. */
    public function myBalance(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => null], 404);
        }

        return response()->json([
            'data' => LeaveType::all()->mapWithKeys(fn ($type) => [
                $type->code => $this->policy->balanceFor($employee->id, $type->id),
            ]),
        ]);
    }

    public function balanceFor(Request $request, string $leaveTypeId): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => $this->policy->balanceFor($employee->id, $leaveTypeId)]);
    }

    /** Eligibility + balance advice for a specific leave type (frontend tips). */
    public function tips(Request $request, string $leaveTypeId): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => $this->policy->tipsFor($employee->id, $leaveTypeId)]);
    }
}
