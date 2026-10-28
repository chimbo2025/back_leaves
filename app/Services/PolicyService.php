<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Policy version resolution + balance/eligibility engine.
 * Mirrors lib/leave.ts (policyEffectiveAt, balanceOf, tenureMonths, tips).
 */
class PolicyService
{
    public const STATUTORY_CODES = ['MATERNITY' => 84, 'PATERNITY' => 7];

    public function __construct(protected BalanceTransactionService $ledger) {}

    /**
     * Live (current) policy for a leave type: the version with no effective_to;
     * falls back to the latest by effective_from. Matches getPolicy/getPolicySafe.
     */
    public function currentPolicy(string $leaveTypeId): ?LeavePolicy
    {
        return (new LeavePolicy)
            ->where('leave_type_id', $leaveTypeId)
            ->whereNull('effective_to')
            ->whereIn('status', ['active', 'approved'])
            ->orderByDesc('priority')
            ->orderByDesc('version')
            ->first()
            ?? (new LeavePolicy)
                ->where('leave_type_id', $leaveTypeId)
                ->whereIn('status', ['active', 'approved'])
                ->orderByDesc('effective_from')
                ->orderByDesc('priority')
                ->orderByDesc('version')
                ->first();
    }

    /** Version pinned by the given date (used when (re)submitting). */
    public function policyEffectiveAt(string $leaveTypeId, ?string $date = null): ?LeavePolicy
    {
        $day = Carbon::parse($date ?? now()->toDateString());
        $covered = (new LeavePolicy)
            ->where('leave_type_id', $leaveTypeId)
            ->where('effective_from', '<=', $day->toDateString())
            ->where(function ($q) use ($day) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day->toDateString());
            })
            ->whereIn('status', ['active', 'approved'])
            ->orderByDesc('priority')
            ->orderByDesc('version')
            ->get();

        if ($covered->isNotEmpty()) {
            // detect overlap if multiple active/approved policies cover the same date
            if ($covered->count() > 1) {
                throw new \InvalidArgumentException('Overlapping active policies found for this leave type on the given effective date.');
            }

            return $covered->first();
        }

        return (new LeavePolicy)
            ->where('leave_type_id', $leaveTypeId)
            ->where('effective_from', '<=', $day->toDateString())
            ->whereIn('status', ['active', 'approved'])
            ->orderByDesc('priority')
            ->orderByDesc('version')
            ->first() ?? $this->currentPolicy($leaveTypeId);
    }

    public function tenureMonths(string $hireDate): int
    {
        $h = Carbon::parse($hireDate);
        $t = Carbon::parse(now()->toDateString());

        return max(0, ($t->year - $h->year) * 12 + ($t->month - $h->month));
    }

    /** Inclusive day count between two dates (frontend dayCount). */
    public function dayCount(string $start, string $end): int
    {
        return Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
    }

    public function serviceYearStart(string $hireDate, ?CarbonInterface $now = null): Carbon
    {
        $hire = Carbon::parse($hireDate);
        $today = Carbon::parse(($now ?? now())->toDateString());
        $start = Carbon::create($today->year, $hire->month, $hire->day);
        while ($start > $today) {
            $start->subYear();
        }

        return $start;
    }

    /**
     * Balance for an employee + leave type, mirroring frontend balanceOf():
     * statutory types are fixed entitlements; others accrue over the service
     * year with a finite carry-over bank expiring in the expiry month.
     */
    public function balanceFor(string $employeeId, string $leaveTypeId): array
    {
        $employee = Employee::findOrFail($employeeId);
        $leave = LeaveType::findOrFail($leaveTypeId);
        $policy = $this->currentPolicy($leaveTypeId);

        $used = LeaveRequest::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', ['approved', 'under_review'])
            ->sum('days');

        // Phase 2 balance decision: add the append-only ledger's net effect for
        // every type that is NOT already represented by dynamic accrual/usage,
        // so an approved waiver truly changes the balance without double
        // counting the dynamic entitlement or the approved-request usage.
        $ledger = $this->ledger->ledgerDelta($employeeId, $leaveTypeId);
        $net = $ledger['net'];

        if (isset(self::STATUTORY_CODES[$leave->code])) {
            $statutory = self::STATUTORY_CODES[$leave->code];

            return [
                'accrued' => (float) $statutory,
                'carryOver' => 0.0,
                'used' => (float) $used,
                'ledgerCredits' => $ledger['credits'],
                'ledgerDebits' => $ledger['debits'],
                'remaining' => (float) max(0, $statutory - $used + $net),
                'pct' => $statutory === 0 ? 0 : (int) round($used / $statutory * 100),
            ];
        }

        $max = (float) ($policy?->max_days_per_year ?? 0);
        $earned = $this->accrualFraction($employee->hire_date, $max);
        $carry = $policy ? $this->carryOverBank($employee, $leaveTypeId, (float) $policy->carry_over_max, $policy->carry_over_expiry_month) : 0.0;
        $accrued = $earned + $carry;
        $remaining = max(0.0, $accrued - $used + $net);

        return [
            'accrued' => (float) $accrued,
            'carryOver' => (float) $carry,
            'used' => (float) $used,
            'ledgerCredits' => $ledger['credits'],
            'ledgerDebits' => $ledger['debits'],
            'remaining' => (float) $remaining,
            'pct' => $accrued <= 0 ? 0 : (int) round($used / $accrued * 100),
        ];
    }

    protected function accrualFraction(string $hireDate, float $maxDaysPerYear): float
    {
        if ($maxDaysPerYear <= 0) {
            return 0;
        }
        $syS = $this->serviceYearStart($hireDate);
        $syE = $syS->copy()->addYear();
        $now = Carbon::parse(now()->toDateString());
        $total = $syE->timestamp - $syS->timestamp;
        $elapsed = max(0, min($now->timestamp - $syS->timestamp, $total));

        return floor($maxDaysPerYear * ($elapsed / $total));
    }

    protected function carryOverBank(Employee $employee, string $leaveTypeId, float $carryOverMax, ?int $expiryMonth): float
    {
        if ($carryOverMax <= 0) {
            return 0;
        }
        // days used in the prior service year (approved | under_review)
        $syStart = $this->serviceYearStart($employee->hire_date);
        $psyStart = $syStart->copy()->subYear();
        $used = LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', ['approved', 'under_review'])
            ->where('start_date', '>=', $psyStart->toDateString())
            ->where('end_date', '<', $syStart->toDateString())
            ->sum('days');
        $policy = $this->currentPolicy($leaveTypeId);
        $max = (float) ($policy?->max_days_per_year ?? 0);
        $leftover = max(0, $max - $used);

        // expiry: end of expiry-month (default 3 = end of March) of the service year
        $expiryEnd = Carbon::create($syStart->year, $expiryMonth ?? 3, 1)->endOfMonth();
        $now = Carbon::parse(now()->toDateString());
        if ($now->greaterThan($expiryEnd)) {
            return 0;
        }

        return min($carryOverMax, $leftover);
    }

    /** Advice list mirroring recommendationsFor() for a not-yet-submitted request. */
    public function tipsFor(string $employeeId, string $leaveTypeId): array
    {
        $employee = Employee::findOrFail($employeeId);
        $leave = LeaveType::findOrFail($leaveTypeId);
        $policy = $this->currentPolicy($leaveTypeId) ?? new LeavePolicy;
        $bal = $this->balanceFor($employeeId, $leaveTypeId);
        $tenure = $this->tenureMonths($employee->hire_date);

        $tips = [];

        if ($tenure < ($policy->min_tenure_months ?? 0)) {
            $tips[] = [
                'tone' => 'warn',
                'text' => "You need at least {$policy->min_tenure_months} months of service for {$leave->name}. You currently have {$tenure}.",
            ];
        } else {
            $tips[] = ['tone' => 'ok', 'text' => "You are eligible to apply for {$leave->name}."];
        }

        if ($bal['accrued'] > 0) {
            $tips[] = [
                'tone' => 'info',
                'text' => "Your {$leave->name} balance is {$bal['remaining']} day(s) ({$bal['used']} used of {$bal['accrued']} — includes {$bal['carryOver']} carried over).",
            ];
        } else {
            $tips[] = [
                'tone' => 'info',
                'text' => "{$leave->name} does not accrue a standing balance; entitlement is assessed per approved request.",
            ];
        }

        if ($policy->requires_documents) {
            foreach (($policy->required_docs ?? []) as $doc) {
                $tips[] = ['tone' => 'warn', 'text' => "A $doc is required for this {$leave->name} request."];
            }
        }

        if (($policy->min_notice_days ?? 0) > 0) {
            $tips[] = [
                'tone' => 'info',
                'text' => "Minimum notice period is {$policy->min_notice_days} days. Submit before ".Carbon::parse(now()->toDateString())->addDays($policy->min_notice_days)->format('M j'),
            ];
        }

        return $tips;
    }
}
