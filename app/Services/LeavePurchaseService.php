<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePaymentCase;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Support\PolicyRules;

/**
 * Leave-purchase rules (Phase 5).
 *
 * A leave purchase is the mirror image of an encashment: instead of the
 * employer paying the employee for accrued leave, the employee pays the
 * employer to buy additional leave days. It has its own policy gate because
 * "leave may be cashed out" and "leave may be bought" are independent
 * decisions, and a purchase is only meaningful where the policy says so.
 *
 * Everything is read from the resolved policy's `leave_rules`, with the
 * organisation-wide defaults in config/leave.php as the floor. Purchase is
 * intentionally conservative: disabled unless a policy explicitly enables it,
 * so a missing rule cannot silently imply "yes, buy as much as you like".
 */
class LeavePurchaseService
{
    public const RULE_ENABLED = 'payment.purchase.enabled';

    public const RULE_MAX_DAYS = 'payment.purchase.max_days';

    /**
     * Evaluate the purchase rules for one case.
     *
     * @param  array<int,array<string,mixed>>  $rules  resolved policy rules
     * @return array{ok:bool,blockers:array<int,string>,max_days:float|null,enabled:bool}
     */
    public function check(
        Employee $employee,
        LeaveType $leaveType,
        ?LeavePolicy $policy,
        array $rules,
        ?float $days,
        string $paymentType,
    ): array {
        if ($paymentType !== LeavePaymentCase::PAYMENT_TYPE_PURCHASE) {
            return ['ok' => true, 'blockers' => [], 'max_days' => null, 'enabled' => true];
        }

        $blockers = [];

        $enabled = PolicyRules::enabled(
            $rules,
            self::RULE_ENABLED,
            (bool) config('leave.payments.purchase_enabled', true),
        );

        $maxDays = PolicyRules::number(
            $rules,
            self::RULE_MAX_DAYS,
            $policy?->max_days_per_request !== null ? (float) $policy->max_days_per_request : null,
        );

        if (! $enabled) {
            $blockers[] = 'Leave purchase is not enabled by policy.';
        }

        if ($days !== null && $maxDays !== null && $days > $maxDays) {
            $blockers[] = sprintf(
                'Requested purchase of %s day(s) exceeds the policy maximum of %s.',
                $this->trim($days),
                $this->trim($maxDays),
            );
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'max_days' => $maxDays,
            'enabled' => $enabled,
        ];
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
