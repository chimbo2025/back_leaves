<?php

namespace App\Services;

use App\Events\LeavePlanConfirmed;
use App\Events\LeavePlanExpired;
use App\Events\LeavePlanReadyForSelection;
use App\Models\Employee;
use App\Models\LeavePlan;
use App\Models\LeavePlanOption;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LeavePlanService (Phase 2 - almanac leave planning).
 *
 * Lifecycle: draft -> proposed -> employee_selected -> manager_review ->
 * confirmed -> converted_to_request. Rejected / expired / cancelled are
 * terminal. Conversion (PART D) re-runs the full proposal validation inside a
 * database transaction and is idempotent: a retry returns the request already
 * created instead of minting a duplicate.
 */
class LeavePlanService
{
    public const PROPOSABLE = ['draft', 'proposed'];

    public const SELECTABLE = ['proposed', 'employee_selected'];

    public const CONFIRMABLE = ['employee_selected', 'manager_review'];

    public const CONVERTIBLE = ['employee_selected', 'manager_review', 'confirmed'];

    public function __construct(
        protected LeaveDateProposalService $proposal,
        protected LeaveRequestService $requests,
        protected PolicyService $policy,
        protected AuditService $audit,
    ) {}

    public function createDraft(string $employeeId, array $data): LeavePlan
    {
        $current = $this->policy->currentPolicy($data['leave_type_id']);

        if (! $current) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'No active approved policy exists for this leave type; a plan cannot reference a broken entitlement.',
            ]);
        }

        return LeavePlan::create([
            'employee_id' => $employeeId,
            'leave_type_id' => $data['leave_type_id'],
            'leave_policy_id' => $data['leave_policy_id'] ?? $current->id,
            'cycle_start' => $data['cycle_start'] ?? null,
            'cycle_end' => $data['cycle_end'] ?? null,
            'status' => 'draft',
        ]);
    }

    /**
     * Generate and persist up to three ranked date options.
     *
     * @return array{plan: LeavePlan, options: array<int,array<string,mixed>>, explanation: ?string}
     */
    public function propose(LeavePlan $plan, ?int $desiredDays = null): array
    {
        if (! in_array($plan->status, self::PROPOSABLE, true)) {
            throw ValidationException::withMessages(['status' => "Only draft/proposed plans can generate options (current: {$plan->status})."]);
        }

        $result = $this->proposal->propose($plan, $desiredDays);

        DB::transaction(function () use ($plan, $result, $desiredDays) {
            $plan->options()->delete();

            foreach ($result['options'] as $index => $option) {
                $plan->options()->create([
                    'option_number' => $index + 1,
                    'start_date' => $option['start_date'],
                    'end_date' => $option['end_date'],
                    'days' => $option['days'],
                    'score' => $option['score'],
                    'score_breakdown_json' => $option['score_breakdown_json'],
                    'availability_status' => $option['availability_status'] ?? 'available',
                ]);
            }

            $plan->update(['status' => 'proposed']);
            $this->audit->append('leave_plan', (string) $plan->id, 'propose', null, [
                'options' => count($result['options']),
                'desired_days' => $desiredDays,
                'explanation' => $result['explanation'],
            ]);
        });

        $fresh = $plan->fresh(['employee', 'leaveType', 'policy', 'options']);

        // Phase 4: tell the employee their options are ready to pick from.
        event(new LeavePlanReadyForSelection($fresh));

        return [
            'plan' => $fresh,
            'options' => $fresh->options->map->toArray()->all(),
            'explanation' => $result['explanation'],
        ];
    }

    public function select(LeavePlan $plan, Employee $actor, int $optionNumber): LeavePlan
    {
        if ($actor->id !== $plan->employee_id) {
            throw ValidationException::withMessages(['authorization' => 'Only the plan owner can select an option.']);
        }

        if (! in_array($plan->status, self::SELECTABLE, true)) {
            throw ValidationException::withMessages(['status' => "Only proposed plans can select an option (current: {$plan->status})."]);
        }

        if ($this->cycleHasPassed($plan)) {
            $plan->update(['status' => 'expired']);

            $expired = $plan->fresh();
            event(new LeavePlanExpired($expired));

            return $expired;
        }

        $option = $plan->options()->where('option_number', $optionNumber)->first();
        if (! $option) {
            throw ValidationException::withMessages(['option_number' => "Option {$optionNumber} does not exist on this plan."]);
        }

        $validation = $this->proposal->validateWindow(
            $plan->employee,
            $plan->leave_type_id,
            Carbon::parse($option->start_date),
            Carbon::parse($option->end_date),
        );

        $this->audit->append('leave_plan', (string) $plan->id, 'select_attempt', $actor->user_id, [
            'option_number' => $optionNumber,
            'blockers' => $validation['blockers'],
        ]);

        if (! $validation['ok']) {
            throw ValidationException::withMessages(['eligibility' => $validation['blockers']]);
        }

        DB::transaction(function () use ($plan, $option, $optionNumber, $actor) {
            $plan->update([
                'selected_option' => (string) $optionNumber,
                'status' => 'employee_selected',
            ]);
            $this->audit->append('leave_plan', (string) $plan->id, 'select', $actor->user_id, [
                'option_number' => $optionNumber,
                'start_date' => $option->start_date->toDateString(),
                'end_date' => $option->end_date->toDateString(),
            ]);
        });

        return $plan->fresh(['employee', 'leaveType', 'policy', 'options']);
    }

    /**
     * Manager confirmation. `confirmed` finalises the dates; `manager_review`
     * parks the plan for a higher confirmation; `rejected` closes it.
     */
    public function confirm(
        LeavePlan $plan,
        Employee $actor,
        string $decision,
        ?string $comment = null,
        ?string $actorUserId = null,
    ): LeavePlan {
        if (! in_array($decision, ['manager_review', 'confirmed', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => "Invalid decision '{$decision}'."]);
        }

        if (! in_array($plan->status, self::CONFIRMABLE, true)) {
            throw ValidationException::withMessages(['status' => "Only employee_selected/manager_review plans can be confirmed (current: {$plan->status})."]);
        }

        if ($this->cycleHasPassed($plan)) {
            $plan->update(['status' => 'expired']);

            $expired = $plan->fresh();
            event(new LeavePlanExpired($expired));

            return $expired;
        }

        $option = $this->selectedOption($plan);
        if ($option === null) {
            throw ValidationException::withMessages(['selected_option' => 'Select an option before confirming the plan.']);
        }

        $validation = $this->proposal->validateWindow(
            $plan->employee,
            $plan->leave_type_id,
            Carbon::parse($option->start_date),
            Carbon::parse($option->end_date),
        );

        if ($decision === 'confirmed' && ! $validation['ok']) {
            throw ValidationException::withMessages(['eligibility' => $validation['blockers']]);
        }

        DB::transaction(function () use ($plan, $decision, $comment, $actor, $option, $validation, $actorUserId) {
            if ($decision === 'confirmed') {
                $plan->update([
                    'status' => 'confirmed',
                    'confirmed_start_date' => $option->start_date->toDateString(),
                    'confirmed_end_date' => $option->end_date->toDateString(),
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                ]);
            } elseif ($decision === 'rejected') {
                $plan->update(['status' => 'rejected']);
            } else {
                $plan->update(['status' => 'manager_review', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            }

            $this->audit->append('leave_plan', (string) $plan->id, $decision, $actorUserId, [
                'decision' => $decision,
                'comment' => $comment,
                'blockers_noticed' => $validation['blockers'],
            ], reason: $comment);
        });

        $fresh = $plan->fresh(['employee', 'leaveType', 'policy', 'options']);

        // Phase 4: a confirmed plan notifies its owner; manager_review and
        // rejected decisions are silent (the employee sees them in-app).
        if ($decision === 'confirmed' && $fresh->status === 'confirmed') {
            event(new LeavePlanConfirmed($fresh));
        }

        return $fresh;
    }

    /**
     * Convert a selected/confirmed plan into a leave request (PART D).
     *
     * Re-runs the proposal window validation, then creates the leave request
     * with the current policy snapshot inside one transaction. Idempotent:
     * converting twice returns the same request and never mints a duplicate.
     *
     * @return array{plan: LeavePlan, request: LeaveRequest}
     */
    public function convert(LeavePlan $plan, Employee $actor, ?string $actorUserId = null): array
    {
        if ($actor->id !== $plan->employee_id) {
            throw ValidationException::withMessages(['authorization' => 'Only the plan owner can convert it into a request.']);
        }

        if ($plan->leave_request_id && $plan->status === 'converted_to_request') {
            $existing = $plan->leaveRequest;

            if ($existing) {
                return ['plan' => $plan->fresh(), 'request' => $existing, 'created' => false];
            }
        }

        if (! in_array($plan->status, self::CONVERTIBLE, true)) {
            throw ValidationException::withMessages(['status' => "This plan cannot be converted from state {$plan->status}."]);
        }

        $option = $this->selectedOption($plan);
        if ($option === null) {
            throw ValidationException::withMessages(['selected_option' => 'Select an option before converting the plan into a request.']);
        }

        $validation = $this->proposal->validateWindow(
            $plan->employee,
            $plan->leave_type_id,
            Carbon::parse($option->start_date),
            Carbon::parse($option->end_date),
        );

        if (! $validation['ok']) {
            throw ValidationException::withMessages(['eligibility' => $validation['blockers']]);
        }

        $policy = $validation['policy'];

        return DB::transaction(function () use ($plan, $option, $policy, $actorUserId) {
            $request = $this->requests->createDraft($plan->employee_id, [
                'leave_type_id' => $plan->leave_type_id,
                'start_date' => $option->start_date->toDateString(),
                'end_date' => $option->end_date->toDateString(),
                'reason' => "Converted from leave plan #{$plan->id} (option {$option->option_number}).",
            ]);

            $request = $this->requests->submit($request, $actorUserId);

            $plan->update([
                'status' => 'converted_to_request',
                'confirmed_start_date' => $option->start_date->toDateString(),
                'confirmed_end_date' => $option->end_date->toDateString(),
                'leave_request_id' => $request->id,
            ]);

            $this->audit->append('leave_plan', (string) $plan->id, 'converted_to_request', $actorUserId, [
                'option_number' => $option->option_number,
                'start_date' => $option->start_date->toDateString(),
                'end_date' => $option->end_date->toDateString(),
                'policy_id' => $policy?->id,
                'request_ref' => $request->ref,
            ]);

            return ['plan' => $plan->fresh(['employee', 'options']), 'request' => $request, 'created' => true];
        });
    }

    public function cancel(LeavePlan $plan, ?string $actorUserId = null): LeavePlan
    {
        if ($plan->status === 'converted_to_request') {
            throw ValidationException::withMessages(['status' => 'A converted plan can no longer be cancelled; withdraw the leave request instead.']);
        }

        DB::transaction(function () use ($plan, $actorUserId) {
            $plan->update(['status' => 'cancelled']);
            $this->audit->append('leave_plan', (string) $plan->id, 'cancelled', $actorUserId, []);
        });

        return $plan->fresh();
    }

    public function selectedOption(LeavePlan $plan): ?LeavePlanOption
    {
        if ($plan->selected_option === null) {
            return null;
        }

        return $plan->options()->where('option_number', (int) $plan->selected_option)->first();
    }

    protected function cycleHasPassed(LeavePlan $plan): bool
    {
        return $plan->cycle_end !== null
            && $plan->cycle_end->lt(now()->toDateString());
    }
}
