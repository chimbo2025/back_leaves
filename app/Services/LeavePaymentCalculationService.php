<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Leave-payment calculation (Phase 5).
 *
 * Turns a salary basis and a number of days into a money amount. It is a pure
 * function of its inputs and writes nothing: the caller stores the returned
 * snapshot on the case. That separation is what lets the same formula be
 * re-run months later against the stored inputs and reproduce the same number.
 *
 * The salary basis is passed in because no payroll table exists in this schema
 * (see docs/phase5-payments.md). The arithmetic is deliberately simple and
 * fully described by the stored snapshot — basis + included allowances, divided
 * by a policy-controlled divisor, times the days — so an amount can always be
 * explained after the fact rather than trusted.
 */
class LeavePaymentCalculationService
{
    /**
     * @param  array{salary_basis?:?string,salary_amount?:float|int|string|null,allowances_included?:array<int,mixed>,allowances_excluded?:array<int,mixed>,days?:float|int|string|null,eligible_days?:float|int|string|null,divisor?:float|int|string|null,currency?:?string,policy_id?:?string,policy_version?:?int}  $input
     * @return array{eligible_amount:float,calculated_amount:float,currency:string,snapshot:array<string,mixed>}
     */
    public function calculate(array $input): array
    {
        $days = (float) ($input['days'] ?? 0);
        $eligibleDays = isset($input['eligible_days']) ? (float) $input['eligible_days'] : $days;
        $salaryAmount = (float) ($input['salary_amount'] ?? 0);
        $included = $input['allowances_included'] ?? [];
        $excluded = $input['allowances_excluded'] ?? [];
        $divisor = (float) ($input['divisor'] ?? config('leave.payments.day_rate_divisor', 30));
        $currency = (string) ($input['currency'] ?? config('leave.payments.currency', 'TZS'));

        if ($divisor <= 0) {
            throw ValidationException::withMessages([
                'divisor' => 'The day-rate divisor must be greater than zero.',
            ]);
        }

        $includedTotal = $this->sumAllowances($included);
        $basis = $salaryAmount + $includedTotal;
        $dayRate = $basis / $divisor;

        $calculated = round($dayRate * $days, 2);
        $eligible = round($dayRate * $eligibleDays, 2);

        return [
            'eligible_amount' => $eligible,
            'calculated_amount' => $calculated,
            'currency' => $currency,
            'snapshot' => [
                'policy_id' => $input['policy_id'] ?? null,
                'policy_version' => $input['policy_version'] ?? null,
                'salary_basis' => $input['salary_basis'] ?? null,
                'salary_amount' => $salaryAmount,
                'allowances_included' => array_values($included),
                'allowances_excluded' => array_values($excluded),
                'included_allowance_total' => $includedTotal,
                'days' => $days,
                'eligible_days' => $eligibleDays,
                'day_rate_divisor' => $divisor,
                'day_rate' => round($dayRate, 4),
                'formula' => sprintf(
                    '((%s + %s) / %s) * %s',
                    $this->trim($salaryAmount),
                    $this->trim($includedTotal),
                    $this->trim($divisor),
                    $this->trim($days),
                ),
                'currency' => $currency,
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Allowances may be given as {name, amount} objects or as bare names. Only
     * the ones carrying an amount affect the basis; a bare name is recorded but
     * adds nothing, so an informational list cannot inflate an amount.
     *
     * @param  array<int,mixed>  $allowances
     */
    private function sumAllowances(array $allowances): float
    {
        $total = 0.0;

        foreach ($allowances as $allowance) {
            if (is_array($allowance) && isset($allowance['amount']) && is_numeric($allowance['amount'])) {
                $total += (float) $allowance['amount'];
            }
        }

        return round($total, 2);
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
