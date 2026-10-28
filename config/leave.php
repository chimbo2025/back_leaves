<?php

/*
|--------------------------------------------------------------------------
| Leave planning configuration (Phase 2)
|--------------------------------------------------------------------------
|
| Date-proposal inputs that are environment/policy data rather than schema:
| public holidays, blackout periods and staffing minimums. Kept in config so a
| deployment (or a test) can set them without a migration.
|
| Shapes:
|   public_holidays  : [['date' => 'Y-m-d', 'end_date' => 'Y-m-d'|null], ...]
|   blackout_periods : [['start_date' => 'Y-m-d', 'end_date' => 'Y-m-d',
|                        'label' => string, 'leave_type_id' => string|null], ...]
|                        leave_type_id null = applies to every leave type.
|   staffing_minimums: ['department_id' => int, 'zone_id' => string|null,
|                        'minimum_present' => int, 'total_staff' => int]
|                        A candidate window is rejected when approving it would
|                        drop present staff below minimum_present.
|
*/

return [

    'public_holidays' => [],

    'blackout_periods' => [],

    'staffing_minimums' => [],

    /*
    |--------------------------------------------------------------------------
    | Notification timings (Phase 4)
    |--------------------------------------------------------------------------
    |
    | Reminder lead times and retry policy for the scheduled notification jobs.
    | These are configuration, not schema: a deployment (or a test) can move a
    | reminder without a migration, and no controller hard-codes a day count.
    |
    | A per-user overrides live in notification_preferences.timing_config_json
    | and are merged over these values by NotificationPreferenceService.
    |
    |   leave_start_days_before      remind N days before an approved leave starts
    |   leave_end_days_before        remind N days before an approved leave ends
    |   return_reminder_days_before  remind N days before the expected return date
    |   late_return_grace_days       days past expected return before alerting
    |   low_balance_threshold        remaining days at/below which to remind
    |   plan_selection_lead_days     remind N days before a proposed plan expires
    |   email_retry_attempts         queued email delivery attempts before giving up
    |   email_retry_backoff          seconds between attempts, indexed by attempt
    |
    */

    'notifications' => [
        'leave_start_days_before' => 2,
        'leave_end_days_before' => 1,
        'return_reminder_days_before' => 1,
        'late_return_grace_days' => 0,
        'low_balance_threshold' => 3.0,
        'plan_selection_lead_days' => 7,
        'email_retry_attempts' => 3,
        'email_retry_backoff' => [60, 300, 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave payments (Phase 5)
    |--------------------------------------------------------------------------
    |
    | Fallback calculation inputs for leave encashment and leave purchase. A
    | policy rule (`payment.day_rate_divisor`) overrides the divisor per leave
    | type; these are the defaults when no rule is configured.
    |
    | No payroll table exists in this schema, so the salary basis itself is
    | supplied by the authorised creator of a case and frozen into the row —
    | see docs/phase5-payments.md for the open policy decision. These settings
    | therefore carry only the arithmetic defaults and the switches that decide
    | whether the purchase/loan restrictions apply when no policy rule is set.
    |
    |   currency            default ISO currency for a new case
    |   day_rate_divisor    salary basis per day = basis / divisor (e.g. 30)
    |   purchase_enabled    default for payment.purchase.enabled
    |   purchase_max_days   default cap on purchased days when no rule is set
    |   allow_statutory_encashment  default for payment.allow_statutory
    |   block_on_outstanding_loan   default for payment.block_on_outstanding_loan
    |
    */

    'payments' => [
        'currency' => 'TZS',
        'day_rate_divisor' => 30,
        'purchase_enabled' => true,
        'purchase_max_days' => null,
        'allow_statutory_encashment' => false,
        'block_on_outstanding_loan' => false,
    ],

];
