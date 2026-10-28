<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Notification;
use Carbon\Carbon;

/**
 * Blueprint Part 4 — notification triggers with a code-level privacy rule:
 * any `sms` message MUST be composed from the privacy-safe template (employee
 * full name + leave dates only; never leave type, reason, or document
 * contents). See the template at the bottom of this class — do not "optimize"
 * it by adding detail, the zonal head only needs to know someone is absent
 * and for how long.
 */
class NotificationService
{
    public function notify(
        string $recipientEmployeeId,
        string $eventType,
        string $message,
        string $channel = 'in_system',
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
        bool $privacySafe = true,
    ): Notification {
        return Notification::create([
            'recipient_employee_id' => $recipientEmployeeId,
            'channel' => $channel,
            'event_type' => $eventType,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id' => $relatedEntityId,
            'message' => $message,
            'is_privacy_safe' => $privacySafe,
            'sent_at' => now(),
        ]);
    }

    public function notifyLeaveDates(Employee $employee, string $start, string $end, bool $sms = false): Notification
    {
        // Privacy-safe template: name + dates only.
        $message = sprintf(
            "%s will be on approved leave from %s to %s.\nPlease adjust court schedule as needed. — DPP Leave System",
            $employee->full_name,
            Carbon::parse($start)->format('M j, Y'),
            Carbon::parse($end)->format('M j, Y'),
        );

        return $this->notify($employee->id, 'leave_starting_soon', $message, $sms ? 'sms' : 'in_system', privacySafe: true);
    }
}
