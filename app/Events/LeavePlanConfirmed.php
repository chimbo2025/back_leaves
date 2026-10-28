<?php

namespace App\Events;

use App\Models\LeavePlan;

/** A leave plan's selected dates were confirmed by the approving authority. */
class LeavePlanConfirmed
{
    public function __construct(public LeavePlan $plan) {}
}
