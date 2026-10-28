<?php

namespace App\Services;

use App\Models\LeavePaymentCase;
use App\Models\User;

/**
 * Payment Overview aggregates.
 *
 * Category definitions (each card counts its own bucket; buckets overlap by
 * design — e.g. every "without request" pair is also "eligible unpaid"):
 *
 * - paid:                    payment_case.status IS paid.
 * - eligible_unpaid:         pair eligible AND (no case OR case status != paid).
 * - without_request:         pair eligible AND no case at all.
 * - leave_taken_unpaid:      approved leave exists, its start date has
 *                            arrived, pair eligible, and no case or not paid.
 * - eligible_no_leave:       pair eligible AND no approved leave request.
 * - pending_hr:              case status pending OR under_hr_review.
 * - pending_accounting:      case status sent_to_accounting OR processing.
 * - processing:              case status processing.
 * - rejected:                case status hr_rejected OR payment_rejected.
 *
 * Money definitions (there is no separate settled-amount column; paid money
 * is the calculated amount at pay time):
 * - total_eligible_amount:   SUM(eligible_amount) over in-scope cases.
 * - total_approved_amount:   SUM(calculated_amount) over hr_approved cases.
 * - total_paid_amount:       SUM(calculated_amount) over paid cases.
 * - outstanding_amount:      SUM(eligible_amount) over unpaid (status != paid)
 *                            cases — money deemed eligible but not yet paid.
 *
 * Case-based figures come from SQL aggregates over the SAME builders the
 * detail endpoints paginate (cloned, counted/summed — rows never loaded).
 * Pair-based figures come from EmployeeLeavePaymentReportService::analyze()
 * over the same filters and scope. Summary and detail therefore cannot drift.
 */
class PaymentSummaryService
{
    public function __construct(
        protected LeavePaymentReportService $cases,
        protected EmployeeLeavePaymentReportService $pairs,
    ) {}

    /** @return array<string,mixed> */
    public function summary(User $user, LeaveReportFilters $filters, string $paymentType): array
    {
        // Accounting sees pair buckets through the same settlement subset as
        // every category endpoint, so summary and detail cannot disagree.
        $subset = $this->cases->audienceFor($user) === LeavePaymentReportService::AUDIENCE_ACCOUNTING
            ? LeavePaymentReportService::ACCOUNTING_STATUSES
            : null;
        $analysis = $this->pairs->analyze($user, $filters, $paymentType, $subset);
        $categories = $this->cases->caseCategories();

        $count = function (string $key) use ($categories, $user, $filters): int {
            $query = $categories[$key]($user, $filters);

            return (clone $query)->count();
        };

        $base = $this->cases->baseCases($user, $filters);
        $eligibleBase = clone $base;
        $totalEligible = (float) $eligibleBase->sum('eligible_amount');
        $approvedBase = clone $base;
        $approvedBase->where('status', LeavePaymentCase::STATUS_HR_APPROVED);
        $paidBase = clone $base;
        $paidBase->where('status', LeavePaymentCase::STATUS_PAID);
        $unpaidBase = clone $base;
        $unpaidBase->where('status', '!=', LeavePaymentCase::STATUS_PAID);

        return [
            'cards' => [
                'total_eligible' => ['total' => $analysis['counts']['eligible']],
                'paid' => ['total' => $count('paid')],
                'eligible_unpaid' => ['total' => $analysis['counts']['eligible_unpaid']],
                'without_payment_request' => ['total' => $analysis['counts']['without_request']],
                'leave_taken_unpaid' => ['total' => $analysis['counts']['leave_taken_unpaid']],
                'eligible_no_leave' => ['total' => $analysis['counts']['eligible_no_leave']],
                'pending_hr' => ['total' => $count('pending_hr')],
                'pending_accounting' => ['total' => $count('pending_accounting')],
                'processing' => ['total' => $count('processing')],
                'rejected' => ['total' => $count('rejected')],
            ],
            'amounts' => [
                'total_eligible_amount' => $totalEligible,
                'total_approved_amount' => (float) $approvedBase->sum('calculated_amount'),
                'total_paid_amount' => (float) $paidBase->sum('calculated_amount'),
                'outstanding_amount' => (float) $unpaidBase->sum('eligible_amount'),
                'currency' => 'TZS',
            ],
        ];
    }
}
