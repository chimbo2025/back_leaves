<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Phase 4 — per-user notification preferences.
 *
 * A preference row is a deliberate opt-out; absence means "defaults" (both
 * channels on, config timing). Mandatory event types can never be turned off:
 * an approval, a rejection, a late-return alert or a security notice that a
 * user could silence would defeat the point of the notification, so
 * emailEnabled()/databaseEnabled() short-circuit to true for them.
 */
class NotificationPreferenceService
{
    /**
     * Event types a user may not disable. These are the operational and
     * security notices the blueprint requires to always reach the recipient:
     * an approval, a rejection, a security notice and a late-return alert.
     *
     * Note the canonical names used by LeaveNotificationService: the legacy
     * 'approved'/'rejected' strings are kept for the approval notifications so
     * historic rows and the existing UI filter continue to match.
     */
    public const MANDATORY_EVENTS = [
        'approved',
        'rejected',
        'late_return_detected',
        'security_alert',
    ];

    /** All event types the API will accept as a preference key. */
    public const OPTIONAL_EVENTS = [
        'submitted',
        'returned_for_correction',
        'leave_starting_soon',
        'leave_ending_soon',
        'return_reminder_due',
        'leave_balance_reminder_due',
        'leave_waiver_decided',
        'leave_plan_ready_for_selection',
        'leave_plan_confirmed',
        'leave_plan_expired',
    ];

    public function isMandatory(string $eventType): bool
    {
        return in_array($eventType, self::MANDATORY_EVENTS, true);
    }

    public function preferenceFor(?User $user, string $eventType): ?NotificationPreference
    {
        if (! $user) {
            return null;
        }

        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_type', $eventType)
            ->first();
    }

    public function emailEnabled(?User $user, string $eventType): bool
    {
        if ($this->isMandatory($eventType)) {
            return true;
        }

        return $this->preferenceFor($user, $eventType)?->email_enabled ?? true;
    }

    public function databaseEnabled(?User $user, string $eventType): bool
    {
        if ($this->isMandatory($eventType)) {
            return true;
        }

        return $this->preferenceFor($user, $eventType)?->database_enabled ?? true;
    }

    public function language(?User $user, string $eventType): string
    {
        return $this->preferenceFor($user, $eventType)?->language ?? 'en';
    }

    /**
     * A per-user timing override falling back to config('leave.notifications.*').
     */
    public function timing(?User $user, string $eventType, string $key, mixed $default = null): mixed
    {
        $config = $this->preferenceFor($user, $eventType)?->timing_config_json ?? [];

        if (array_key_exists($key, $config)) {
            return $config[$key];
        }

        return config("leave.notifications.{$key}", $default);
    }

    /**
     * Upsert a preference row. A mandatory event type may still be stored (the
     * UI may show it as locked), but the stored flags never widen access:
     * emailEnabled()/databaseEnabled() ignore them for mandatory types.
     *
     * @param  array{email_enabled?:bool,database_enabled?:bool,language?:string,timing_config_json?:array<string,mixed>|null}  $attributes
     */
    public function set(User $user, string $eventType, array $attributes): NotificationPreference
    {
        return NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'event_type' => $eventType],
            $attributes,
        );
    }

    /**
     * The full preference view for a user: every known event type with its
     * effective channel flags, whether it may be disabled, and the language.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(User $user): array
    {
        $stored = NotificationPreference::where('user_id', $user->id)->get()->keyBy('event_type');

        $out = [];
        foreach (array_merge(self::MANDATORY_EVENTS, self::OPTIONAL_EVENTS) as $eventType) {
            $row = $stored->get($eventType);
            $mandatory = $this->isMandatory($eventType);

            $out[] = [
                'event_type' => $eventType,
                'mandatory' => $mandatory,
                'email_enabled' => $mandatory ? true : ($row?->email_enabled ?? true),
                'database_enabled' => $mandatory ? true : ($row?->database_enabled ?? true),
                'language' => $row?->language ?? 'en',
                'timing_config' => $row?->timing_config_json ?? [],
            ];
        }

        return $out;
    }
}
