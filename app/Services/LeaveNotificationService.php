<?php

namespace App\Services;

use App\Jobs\SendNotificationEmail;
use App\Models\Employee;
use App\Models\LeavePlan;
use App\Models\LeaveRequest;
use App\Models\LeaveReturn;
use App\Models\LeaveWaiver;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\ReportingLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 4 — the single gateway every leave notification goes through.
 *
 * It adds, on top of NotificationService's in-system write:
 *   - channel selection per user preference (database + email),
 *   - a notification_deliveries row per channel for delivery tracking,
 *   - a queued email delivery with retry/idempotency,
 *   - idempotency for scheduled reminders, and
 *   - the privacy rule: leave type and reason are included only for a recipient
 *     who holds notifications.view_sensitive. A line manager who does not hold
 *     it receives the person's name and dates and nothing about the medical or
 *     disciplinary substance of the leave.
 *
 * The low-level NotificationService is left untouched so the existing
 * assignment/handover notifications keep working; this service is the layer the
 * Phase 4 domain events and reminder jobs speak to.
 */
class LeaveNotificationService
{
    public function __construct(
        protected NotificationService $notifications,
        protected NotificationPreferenceService $preferences,
        protected PermissionService $permissions,
    ) {}

    /**
     * Create one in-system notification and fan it out over the enabled
     * channels, recording a delivery row per channel.
     *
     * Idempotency: when `idempotencyKey` is supplied (scheduled reminders) a
     * second call with the same base key returns the existing notification
     * without sending again. Real-time lifecycle events pass no key and are
     * always delivered.
     *
     * @param  array{relatedEntityType?:?string,relatedEntityId?:?int,privacySafe?:bool,referenceId?:int|string|null,date?:?string,idempotencyKey?:?string,email?:bool}  $options
     */
    public function send(string $recipientEmployeeId, string $eventType, string $message, array $options = []): ?Notification
    {
        $employee = Employee::find($recipientEmployeeId);
        if (! $employee) {
            return null;
        }

        $user = $employee->user;
        $databaseEnabled = $this->preferences->databaseEnabled($user, $eventType);
        // `email => false` keeps an event in-system only. Submission uses it:
        // intermediate/arrival notices stay in the bell, and only the final
        // approval is emailed to the requester.
        $emailEnabled = ($options['email'] ?? true) && $this->preferences->emailEnabled($user, $eventType);

        // An optional notification with both channels off produces nothing.
        if (! $databaseEnabled && ! $emailEnabled) {
            return null;
        }

        $idempotent = array_key_exists('idempotencyKey', $options) && $options['idempotencyKey'] !== null;
        $baseKey = $idempotent
            ? (string) $options['idempotencyKey']
            : $this->idempotencyKey(
                $recipientEmployeeId,
                $eventType,
                $options['referenceId'] ?? $options['relatedEntityId'] ?? $employee->id,
                $options['date'] ?? now()->toDateString(),
            );

        if ($idempotent) {
            $existing = NotificationDelivery::with('notification')
                ->where('idempotency_key', 'like', $baseKey.':%')
                ->first();

            if ($existing) {
                return $existing->notification;
            }
        }

        $notification = $this->notifications->notify(
            $recipientEmployeeId,
            $eventType,
            $message,
            'in_system',
            $options['relatedEntityType'] ?? null,
            $options['relatedEntityId'] ?? null,
            $options['privacySafe'] ?? true,
        );

        if ($databaseEnabled) {
            NotificationDelivery::create([
                'notification_id' => $notification->id,
                'channel' => NotificationDelivery::CHANNEL_DATABASE,
                'recipient' => $recipientEmployeeId,
                'status' => NotificationDelivery::STATUS_SENT,
                'sent_at' => now(),
                'attempts' => 1,
                'idempotency_key' => $baseKey.':database',
            ]);
        }

        if ($emailEnabled && $employee->email) {
            $delivery = NotificationDelivery::create([
                'notification_id' => $notification->id,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'recipient' => $employee->email,
                'status' => NotificationDelivery::STATUS_QUEUED,
                'attempts' => 0,
                'idempotency_key' => $baseKey.':email',
            ]);

            SendNotificationEmail::dispatch($delivery->id);
        }

        return $notification;
    }

    /** employee:{id}:event:{event}:reference:{ref}:date:{date} */
    public function idempotencyKey(string $employeeId, string $eventType, int|string|null $referenceId, string $date): string
    {
        return sprintf(
            'employee:%s:event:%s:reference:%s:date:%s',
            $employeeId,
            $eventType,
            $referenceId === null ? '0' : $referenceId,
            $date,
        );
    }

    // -----------------------------------------------------------------
    // Domain notifications
    // -----------------------------------------------------------------

    public function requestSubmitted(LeaveRequest $request, Employee $approver): ?Notification
    {
        return $this->send($approver->id, 'submitted', "{$request->ref} was submitted and awaits your review.", [
            'relatedEntityType' => 'leave_request',
            'relatedEntityId' => $request->id,
            'email' => false,
        ]);
    }

    public function requestApproved(LeaveRequest $request): ?Notification
    {
        $employee = $request->employee;
        $message = sprintf(
            '%s fully approved (%s – %s).',
            $request->ref,
            Carbon::parse($request->start_date)->format('M j'),
            Carbon::parse($request->end_date)->format('M j'),
        );

        return $this->send($employee->id, 'approved', $message, [
            'relatedEntityType' => 'leave_request',
            'relatedEntityId' => $request->id,
        ]);
    }

    public function requestRejected(LeaveRequest $request, Employee $actor, ?string $comments = null): ?Notification
    {
        $message = "{$request->ref} was rejected by {$actor->full_name}.";
        if ($comments) {
            $message .= " Reason: {$comments}";
        }

        return $this->send($request->employee_id, 'rejected', $message, [
            'relatedEntityType' => 'leave_request',
            'relatedEntityId' => $request->id,
        ]);
    }

    public function requestReturned(LeaveRequest $request, ?string $comments = null): ?Notification
    {
        $message = "{$request->ref} was returned for correction";
        $message .= $comments ? ": {$comments}" : '.';

        return $this->send($request->employee_id, 'returned_for_correction', $message, [
            'relatedEntityType' => 'leave_request',
            'relatedEntityId' => $request->id,
        ]);
    }

    public function leaveStartingSoon(LeaveRequest $request): ?Notification
    {
        $message = sprintf(
            'Your %s leave starts %s. Ensure handover and documents are in order.',
            $request->leaveType?->name ?? 'leave',
            Carbon::parse($request->start_date)->format('M j, Y'),
        );

        return $this->send($request->employee_id, 'leave_starting_soon', $message, [
            'relatedEntityType' => 'leave_request',
            'relatedEntityId' => $request->id,
            'referenceId' => $request->id,
            'date' => $request->start_date->toDateString(),
            'idempotencyKey' => $this->idempotencyKey($request->employee_id, 'leave_starting_soon', $request->id, $request->start_date->toDateString()),
        ]);
    }

    public function leaveEndingSoon(LeaveRequest $request): ?Notification
    {
        $message = sprintf(
            'Your %s leave ends %s. Report your return when you resume duty.',
            $request->leaveType?->name ?? 'leave',
            Carbon::parse($request->end_date)->format('M j, Y'),
        );

        return $this->send($request->employee_id, 'leave_ending_soon', $message, [
            'relatedEntityType' => 'leave_request',
            'relatedEntityId' => $request->id,
            'referenceId' => $request->id,
            'date' => $request->end_date?->toDateString(),
            'idempotencyKey' => $this->idempotencyKey($request->employee_id, 'leave_ending_soon', $request->id, (string) $request->end_date?->toDateString()),
        ]);
    }

    public function returnReminder(LeaveReturn $return): ?Notification
    {
        $expected = $return->expected_return_date?->format('M j, Y') ?? 'the expected date';
        $message = "Your leave ends soon. Expected return date is {$expected}; please report your return to duty.";

        return $this->send($return->employee_id, 'return_reminder_due', $message, [
            'relatedEntityType' => 'leave_return',
            'relatedEntityId' => $return->id,
            'referenceId' => $return->leave_request_id ?? $return->id,
            'date' => $return->expected_return_date?->toDateString(),
            'idempotencyKey' => $this->idempotencyKey($return->employee_id, 'return_reminder_due', $return->leave_request_id ?? $return->id, (string) $return->expected_return_date?->toDateString()),
        ]);
    }

    /**
     * Alert the employee and their line managers that a return is overdue.
     *
     * Managers receive the name and the expected return date only; the leave
     * type and any reason are appended only for a recipient holding
     * notifications.view_sensitive. This is the code-level guarantee that a
     * sick-leave reason never leaks into a manager's inbox by accident.
     */
    public function lateReturnDetected(LeaveReturn $return): array
    {
        $employee = $return->employee;
        $expected = $return->expected_return_date?->format('M j, Y') ?? 'the expected date';
        $referenceDate = $return->expected_return_date?->toDateString() ?? now()->toDateString();

        $sent = [];

        $sent[] = $this->send(
            $employee->id,
            'late_return_detected',
            "You have not reported your return to duty (expected {$expected}). Please report as soon as possible.",
            [
                'relatedEntityType' => 'leave_return',
                'relatedEntityId' => $return->id,
                'referenceId' => $return->leave_request_id ?? $return->id,
                'date' => $referenceDate,
                'idempotencyKey' => $this->idempotencyKey($employee->id, 'late_return_detected', $return->leave_request_id ?? $return->id, $referenceDate),
            ],
        );

        foreach ($this->managersFor($employee) as $manager) {
            $sensitive = $this->canSeeSensitive($manager);
            $message = sprintf('%s has not reported back from leave (expected %s).', $employee->full_name, $expected);
            if ($sensitive) {
                $message .= sprintf(' Leave type: %s.', $return->leaveRequest?->leaveType?->name ?? 'unknown');
                if ($return->leaveRequest?->reason) {
                    $message .= " Reason: {$return->leaveRequest->reason}";
                }
            }

            $sent[] = $this->send($manager->id, 'late_return_detected', $message, [
                'relatedEntityType' => 'leave_return',
                'relatedEntityId' => $return->id,
                'referenceId' => $return->leave_request_id ?? $return->id,
                'date' => $referenceDate,
                'privacySafe' => ! $sensitive,
                'idempotencyKey' => $this->idempotencyKey($manager->id, 'late_return_detected', $return->leave_request_id ?? $return->id, $referenceDate),
            ]);
        }

        return array_values(array_filter($sent));
    }

    /**
     * @param  array<string,mixed>  $balance
     */
    public function balanceReminder(Employee $employee, string $leaveTypeId, array $balance): ?Notification
    {
        $remaining = $balance['remaining'] ?? $balance['available'] ?? null;
        $name = $balance['leave_type_name'] ?? 'leave';
        $context = $balance['context'] ?? 'low_balance';

        if ($context === 'cycle_end') {
            $cycleEnd = $balance['cycle_end'] ?? null;
            $message = sprintf(
                'Your leave cycle ends %s. Unused days may expire or carry over per policy — review your leave plan.',
                $cycleEnd ? Carbon::parse($cycleEnd)->format('M j, Y') : 'soon',
            );
            $date = $cycleEnd ? (string) $cycleEnd : now()->toDateString();
        } else {
            $message = sprintf(
                'Your %s balance is low (%s day(s) remaining). Plan accordingly.',
                $name,
                is_numeric($remaining) ? rtrim(rtrim(number_format((float) $remaining, 2, '.', ''), '0'), '.') : 'few',
            );
            $date = now()->format('Y-m');
        }

        return $this->send($employee->id, 'leave_balance_reminder_due', $message, [
            'relatedEntityType' => 'leave_balance',
            'relatedEntityId' => null,
            'referenceId' => $leaveTypeId,
            'date' => $date,
            'idempotencyKey' => $this->idempotencyKey($employee->id, 'leave_balance_reminder_due', $leaveTypeId, $date),
        ]);
    }

    public function waiverDecided(LeaveWaiver $waiver, bool $approved): ?Notification
    {
        $message = sprintf(
            'Your leave %s request was %s.',
            $waiver->requested_action ?? 'waiver',
            $approved ? 'approved' : 'rejected',
        );

        return $this->send($waiver->employee_id, 'leave_waiver_decided', $message, [
            'relatedEntityType' => 'leave_waiver',
            'relatedEntityId' => $waiver->id,
            'referenceId' => $waiver->id,
        ]);
    }

    public function planReadyForSelection(LeavePlan $plan): ?Notification
    {
        $message = 'Your leave plan has proposed dates ready — open the planner to choose one.';

        return $this->send($plan->employee_id, 'leave_plan_ready_for_selection', $message, [
            'relatedEntityType' => 'leave_plan',
            'relatedEntityId' => $plan->id,
            'referenceId' => $plan->id,
        ]);
    }

    public function planConfirmed(LeavePlan $plan): ?Notification
    {
        $message = sprintf(
            'Your leave plan was confirmed for %s – %s.',
            $plan->confirmed_start_date?->format('M j') ?? '',
            $plan->confirmed_end_date?->format('M j') ?? '',
        );

        return $this->send($plan->employee_id, 'leave_plan_confirmed', $message, [
            'relatedEntityType' => 'leave_plan',
            'relatedEntityId' => $plan->id,
            'referenceId' => $plan->id,
        ]);
    }

    public function planExpired(LeavePlan $plan): ?Notification
    {
        $message = 'Your leave plan expired before a date was confirmed. Create a new plan for the next cycle.';

        return $this->send($plan->employee_id, 'leave_plan_expired', $message, [
            'relatedEntityType' => 'leave_plan',
            'relatedEntityId' => $plan->id,
            'referenceId' => $plan->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Privacy + recipient resolution
    // -----------------------------------------------------------------

    /** Does this recipient hold the explicit right to see leave detail? */
    public function canSeeSensitive(Employee $recipient): bool
    {
        $user = $recipient->user;

        if (! $user) {
            return false;
        }

        return $this->permissions->can($user, 'notifications', 'view_sensitive');
    }

    /**
     * Direct line managers of an employee (their reporting-line supervisors).
     *
     * @return Collection<int,Employee>
     */
    protected function managersFor(Employee $employee): Collection
    {
        $supervisorIds = ReportingLine::query()
            ->where('employee_id', $employee->id)
            ->pluck('supervisor_employee_id')
            ->filter()
            ->unique()
            ->all();

        if ($supervisorIds === []) {
            return collect();
        }

        return Employee::whereIn('id', $supervisorIds)->get();
    }
}
