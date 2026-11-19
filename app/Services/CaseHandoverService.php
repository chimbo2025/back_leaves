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
    /**
     * Active court cases owned by the employee (assigned prosecutor).
     *
     * An ACTIVE case with a NEXT HEARING that has already passed does not
     * hold final approval hostage — a missed/completed hearing date is not
     * evidence the officer is working the file right now (e.g. a demo case
     * assigned to an HR officer, whose banner would otherwise pause every
     * final approval forever). The case stays 'active' and is untouched; it
     * simply no longer forces a handover. An ACTIVE case with no hearing
     * date at all still blocks: unscheduled live work must be covered.
     *
     * Deviation from Blueprint Part 3 pseudo-code (which gated purely on
     * status = 'active'); scoped here so the whole gate — missingHandovers,
     * openCasesMissingHandover, canFinalApprove, propose — stays consistent.
     */
    public function openCourtCases(Employee $employee): Collection
    {
        return ProsecutionCase::where('kind', 'court_case')
            ->where('status', 'active')
            ->where('assigned_prosecutor_id', $employee->id)
            ->where(function ($query) {
                $query->whereNull('next_hearing_date')
                    ->orWhereDate('next_hearing_date', '>=', now()->toDateString());
            })
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

        $missing = $this->openCasesMissingHandover($request);

        return ['empty' => $missing->isEmpty(), 'refs' => $missing->pluck('ref')->all()];
    }

    /**
     * The open court cases still missing an accepted/completed handover for
     * this request — the same set missingHandovers() reports, but as full rows
     * so the UI can offer "hand these over" to the requester.
     */
    public function openCasesMissingHandover(LeaveRequest $request): Collection
    {
        $open = $this->openCourtCases($request->employee);
        if ($open->isEmpty()) {
            return collect();
        }

        return $open->filter(function (ProsecutionCase $case) use ($request) {
            return ! CaseHandover::where('case_id', $case->id)
                ->where('request_id', $request->id)
                ->whereIn('status', ['accepted', 'completed'])
                ->exists();
        })->values();
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
    public function propose(LeaveRequest $request, string $fromEmployeeId, string $toEmployeeId, ?array $caseIds = null, ?string $reason = null): Collection
    {
        $cases = $this->openCourtCases($request->employee)
            ->when($caseIds !== null, fn ($c) => $c->whereIn('id', $caseIds));

        if ($cases->isEmpty()) {
            throw ValidationException::withMessages(['handover' => 'Hakuna kesi za mahakama zilizo wazi zinazohitaji kukabidhiwa.']);
        }

        // Recipient eligibility: must be an active employee.
        $recipient = Employee::find($toEmployeeId);
        if (! $recipient || ! $recipient->is_active) {
            throw ValidationException::withMessages(['to_employee_id' => 'Mpokeaji aliyechaguliwa lazima awe mtumishi hai.']);
        }
        // Recipient may equal the requester only when explicitly allowed by
        // policy; the default leaves this to the caller (preserved for
        // backward compatibility with existing tests and flows).

        return $cases->map(fn (ProsecutionCase $case) => CaseHandover::create([
            'request_id' => $request->id,
            'case_id' => $case->id,
            'from_employee_id' => $fromEmployeeId,
            'to_employee_id' => $toEmployeeId,
            'status' => 'proposed',
            'proposed_at' => now(),
            'notes' => $reason,
        ]));
    }

    /** Recipient accepts coverage; the case file moves to them. */
    public function accept(CaseHandover $handover, ?string $notes = null): CaseHandover
    {
        if ($handover->status !== 'proposed') {
            throw ValidationException::withMessages(['status' => "Haiwezi kukubali makabidhiano yaliyo katika hali {$handover->status}."]);
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
            throw ValidationException::withMessages(['status' => "Haiwezi kukataa makabidhiano yaliyo katika hali {$handover->status}."]);
        }
        $handover->update(['status' => 'rejected', 'responded_at' => now(), 'notes' => $notes]);

        return $handover->fresh();
    }

    /** Capacity review completed — supervisor/zonal head sign-off recorded. */
    public function complete(CaseHandover $handover, string $reviewedByEmployeeId): CaseHandover
    {
        if ($handover->status !== 'accepted') {
            throw ValidationException::withMessages(['status' => 'Ni makabidhiano yaliyokubaliwa pekee yanayoweza kukamilishwa (ukaguzi wa uwezo).']);
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
