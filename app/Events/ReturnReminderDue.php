<?php

namespace App\Events;

use App\Models\LeaveReturn;

/** The expected return date of an open return-to-work case is approaching. */
class ReturnReminderDue
{
    public function __construct(public LeaveReturn $return) {}
}
