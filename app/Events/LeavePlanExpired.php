<?php

namespace App\Events;

use App\Models\LeavePlan;

/** A leave plan passed its cycle before a selection was confirmed. */
class LeavePlanExpired
{
    public function __construct(public LeavePlan $plan) {}
}
