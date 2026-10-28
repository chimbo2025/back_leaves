<?php

namespace App\Services;

use App\Models\CaseHandover;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ProsecutionCase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Blueprint Part 3 — handover state machine and the final-approval capacity
 * gate. A leave request whose requester still owns an ACTIVE court case
 * cannot be fully approved until every court case has an ACCEPTED (or
 * completed) handover linked to that request. Rejection pauses the request
 * (status stays under_review, current_owner null).
 */
class CaseHandoverService
{
    /** Active court cases owned by the employee (assigned prosecutor). */
    public function openCourtCases(Employee $employee): Collection
    {
        return ProsecutionCase::where('kind', 'court_case')
            ->where('status', 'active')
            ->where('assigned_prosecutor_id', $employee->id)
            ->orderBy('next_hearing_date')
            ->get();
    }

    /**
     * Required-but-unaccepted handovers for the request window.
     *
     * @return array{empty: bool, refs: array<int,string>}
     */
    public function missingHandovers(LeaveRequest $request): array
    {
        $open = $this->openCourtCases($request->employee);
        if ($open->isEmpty()) {
            return ['empty' => true, 'refs' => []];
        }

        $missing = $open->filter(function (ProsecutionCase $case) use ($request) {
            return ! CaseHandover::where('case_id', $case->id)
                ->where('request_id', $request->id)
                ->whereIn('status', ['accepted', 'completed'])
                ->exists();
        });

        return ['empty' => $missing->isEmpty(), 'refs' => $missing->pluck('ref')->all()];
    }

    /** True when the employee has no active court cases pending handover. */
    public function canFinalApprove(Employee $employee): bool
    {
        return $this->openCourtCases($employee)->isEmpty();
    }

    /**
     * Propose handovers for every open court case of the requester (or a
     * subset given in $caseIds). Each open case gets its own handover record.
     *
     * @param  array<int,int>|null  $caseIds
     * @return Collection<int,CaseHandover>
     */
    public function propose(LeaveRequest $request, string $fromEmployeeId, string $toEmployeeId, ?array $caseIds = null): Collection
    {
        $cases = $this->openCourtCases($request->employee)
            ->when($caseIds !== null, fn ($c) => $c->whereIn('id', $caseIds));

        if ($cases->isEmpty()) {
            throw ValidationException::withMessages(['handover' => 'No open court cases need handing over.']);
        }

        return $cases->map(fn (ProsecutionCase $case) => CaseHandover::create([
            'request_id' => $request->id,
            'case_id' => $case->id,
            'from_employee_id' => $fromEmployeeId,
            'to_employee_id' => $toEmployeeId,
            'status' => 'proposed',
            'proposed_at' => now(),
        ]));
    }

    /** Recipient accepts coverage; the case file moves to them. */
    public function accept(CaseHandover $handover, ?string $notes = null): CaseHandover
    {
        if ($handover->status !== 'proposed') {
            throw ValidationException::withMessages(['status' => "Cannot accept a handover that is {$handover->status}."]);
        }
        $handover->update(['status' => 'accepted', 'responded_at' => now(), 'notes' => $notes]);

        $case = $handover->case;
        if ($case->kind === 'court_case') {
            $case->update(['owner_id' => $handover->to_employee_id]);
        }

        return $handover->fresh();
    }

    /** Recipient declines; the handover returns to proposed-able state. */
    public function reject(CaseHandover $handover, ?string $notes = null): CaseHandover
    {
        if (! in_array($handover->status, ['proposed', 'accepted'], true)) {
            throw ValidationException::withMessages(['status' => "Cannot reject a handover that is {$handover->status}."]);
        }
        $handover->update(['status' => 'rejected', 'responded_at' => now(), 'notes' => $notes]);

        return $handover->fresh();
    }

    /** Capacity review completed — supervisor/zonal head sign-off recorded. */
    public function complete(CaseHandover $handover, string $reviewedByEmployeeId): CaseHandover
    {
        if ($handover->status !== 'accepted') {
            throw ValidationException::withMessages(['status' => 'Only accepted handovers can be completed (capacity review).']);
        }
        $handover->update(['status' => 'completed', 'reviewed_by' => $reviewedByEmployeeId, 'reviewed_at' => now()]);

        return $handover->fresh();
    }

    /** Dockets are per-request continuity records; closed when rejected. */
    public function closeDockets(LeaveRequest $request): void
    {
        ProsecutionCase::where('kind', 'docket')
            ->where('request_id', $request->id)
            ->update(['status' => 'closed', 'owner_id' => $request->employee_id]);
    }

    /** Dockets are returned to the requester once the request completes. */
    public function returnDockets(LeaveRequest $request): void
    {
        ProsecutionCase::where('kind', 'docket')
            ->where('request_id', $request->id)
            ->update(['owner_id' => $request->employee_id]);
    }
}
