<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeavePaymentCase;
use Illuminate\Support\Collection;

/**
 * Accounting notification (Phase 5).
 *
 * Tells the people who can settle a payment that an approved case is waiting
 * for them. It exists as its own service because it has one hard rule that the
 * generic notifier must not be trusted to remember: **Accounting never sees
 * medical or leave-substance detail.** A payment case can arise from maternity,
 * paternity or sick leave, and an accountant needs the amount and a reference,
 * not the reason.
 *
 * The message is therefore built from the payment facts alone — reference,
 * employee name, amount, currency — and never from the leave type or the
 * request reason, whatever the recipient's other permissions are.
 *
 * Recipients are resolved by capability, not by a hard-coded role: anyone
 * holding leave_payments.receive or leave_payments.process is an accountant for
 * this purpose. That keeps the role set and the notification in step.
 */
class AccountingNotificationService
{
    public function __construct(
        protected LeaveNotificationService $notifications,
        protected PermissionService $permissions,
    ) {}

    /**
     * Notify every accountant that a case is ready to settle.
     *
     * Idempotent per recipient via the notification idempotency key, so a
     * retried send-to-Accounting cannot produce a second email.
     *
     * @return int number of recipients actually notified
     */
    public function notify(LeavePaymentCase $case): int
    {
        $sent = 0;

        foreach ($this->recipients() as $employee) {
            $notification = $this->notifications->send(
                $employee->id,
                'leave_payment_sent_to_accounting',
                $this->message($case),
                [
                    'relatedEntityType' => 'leave_payment_case',
                    'relatedEntityId' => $case->id,
                    'referenceId' => $case->id,
                    'privacySafe' => true,
                    'idempotencyKey' => "payment:{$case->id}:sent-to-accounting:{$employee->id}",
                ],
            );

            if ($notification) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The exact text an accountant receives. Deliberately free of leave type,
     * leave reason and any medical detail; the caller passes this straight to
     * the notifier so there is a single place the privacy rule lives.
     *
     * @return Collection<int,Employee>
     */
    public function recipients(): Collection
    {
        return Employee::query()
            ->where('is_active', true)
            ->with('user')
            ->get()
            ->filter(function (Employee $employee): bool {
                $user = $employee->user;

                if (! $user) {
                    return false;
                }

                return $this->permissions->can($user, 'leave_payments', 'receive')
                    || $this->permissions->can($user, 'leave_payments', 'process');
            })
            ->values();
    }

    public function message(LeavePaymentCase $case): string
    {
        $amount = $case->calculated_amount;
        $currency = $case->currency ?: config('leave.payments.currency', 'TZS');
        $name = $case->employee?->full_name ?? 'An employee';

        return sprintf(
            'Payment case #%d for %s is approved and ready to settle: %s %s. Refer to the case record for details.',
            $case->id,
            $name,
            $currency,
            $amount === null ? 'amount pending' : number_format((float) $amount, 2),
        );
    }
}
