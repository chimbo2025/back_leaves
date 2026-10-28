<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\LeaveReturn;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Return-to-work reporting (Phase 3).
 *
 * openForRequest() is called when a leave request is finally approved: it
 * freezes the expected return date and its calculation snapshot on a
 * leave_returns row. The employee later reports their actual return; a late
 * return requires an explanation and is parked for review. Nothing here ever
 * touches a balance — a deduction is the reviewer's decision in LateReturnService.
 */
class ReturnToWorkService
{
    public function __construct(
        protected ExpectedReturnDateService $expected,
        protected AuditService $audit,
    ) {}

    /**
     * Open the return-to-work case for an approved request. Idempotent: one case
     * per leave request, enforced by the unique index and firstOrCreate.
     */
    public function openForRequest(LeaveRequest $request, ?string $actorUserId = null): ?LeaveReturn
    {
        if ($request->status !== 'approved' || ! $request->end_date) {
            return null;
        }

        $calc = $this->expected->compute($request);
        if ($calc === null) {
            return null;
        }

        $return = LeaveReturn::firstOrCreate(
            ['leave_request_id' => $request->id],
            [
                'employee_id' => $request->employee_id,
                'expected_return_date' => $calc['expected_return_date'],
                'status' => LeaveReturn::STATUS_PENDING,
                'policy_snapshot' => $calc['snapshot'],
            ],
        );

        if ($return->wasRecentlyCreated) {
            $this->audit->append('leave_return', (string) $return->id, 'opened', $actorUserId, [
                'leave_request_id' => $request->id,
                'expected_return_date' => $calc['expected_return_date'],
            ]);
        }

        return $return;
    }

    /**
     * Record the employee's actual return. Late reports must carry an
     * explanation and are set to late_pending_review; no balance change happens.
     *
     * @param  array{actual_return_date:string, explanation?:?string, supporting_document_id?:?int}  $data
     */
    public function reportReturn(User $actor, LeaveReturn $return, array $data): LeaveReturn
    {
        $employee = $actor->employee;

        if (! $employee || $return->employee_id !== $employee->id) {
            throw new AuthorizationException('You can only report your own return to work.');
        }

        $actual = Carbon::parse($data['actual_return_date'])->startOfDay();
        $expected = Carbon::parse($return->expected_return_date->toDateString());

        // Retry safety: reporting the identical return date again is a no-op.
        if ($return->actual_return_date
            && $return->actual_return_date->toDateString() === $actual->toDateString()
            && $return->status !== LeaveReturn::STATUS_PENDING) {
            return $return->fresh();
        }

        if ($return->status !== LeaveReturn::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => "This return has already been reported (current: {$return->status}).",
            ]);
        }

        $lateDays = $actual->greaterThan($expected) ? (int) $expected->diffInDays($actual) : 0;
        $explanation = trim((string) ($data['explanation'] ?? ''));

        if ($lateDays > 0 && $explanation === '') {
            throw ValidationException::withMessages([
                'explanation' => 'A late return requires an explanation before it can be reviewed.',
            ]);
        }

        $status = $lateDays > 0
            ? LeaveReturn::STATUS_LATE_PENDING_REVIEW
            : LeaveReturn::STATUS_REPORTED_ON_TIME;

        return DB::transaction(function () use ($actor, $employee, $return, $actual, $expected, $lateDays, $explanation, $status, $data) {
            $return->update([
                'actual_return_date' => $actual->toDateString(),
                'status' => $status,
                'reported_by' => $employee->id,
                'reported_at' => now(),
                'late_days' => $lateDays,
                'explanation' => $explanation !== '' ? $explanation : $return->explanation,
                'explanation_status' => $explanation !== '' ? 'submitted' : $return->explanation_status,
                'supporting_document_id' => $data['supporting_document_id'] ?? $return->supporting_document_id,
            ]);

            $this->audit->append('leave_return', (string) $return->id, 'reported', $actor->id, [
                'leave_request_id' => $return->leave_request_id,
                'actual_return_date' => $actual->toDateString(),
                'expected_return_date' => $expected->toDateString(),
                'late_days' => $lateDays,
            ], reason: $explanation !== '' ? $explanation : null);

            return $return->fresh();
        });
    }

    /**
     * Supply or amend the explanation for a late return that is awaiting review.
     */
    public function submitExplanation(User $actor, LeaveReturn $return, string $explanation): LeaveReturn
    {
        $employee = $actor->employee;

        if (! $employee || $return->employee_id !== $employee->id) {
            throw new AuthorizationException('You can only explain your own return to work.');
        }

        if ($return->status !== LeaveReturn::STATUS_LATE_PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'status' => "An explanation can only be supplied for a late return awaiting review (current: {$return->status}).",
            ]);
        }

        return DB::transaction(function () use ($actor, $return, $explanation) {
            $return->update([
                'explanation' => $explanation,
                'explanation_status' => 'submitted',
            ]);

            $this->audit->append('leave_return', (string) $return->id, 'explanation_submitted', $actor->id, [
                'leave_request_id' => $return->leave_request_id,
                'late_days' => (int) $return->late_days,
            ], reason: $explanation);

            return $return->fresh();
        });
    }
}
