<?php

namespace App\Events;

use App\Models\LeavePlan;

/** A leave plan received date proposals and is ready for the employee to select. */
class LeavePlanReadyForSelection
{
    public function __construct(public LeavePlan $plan) {}
}
