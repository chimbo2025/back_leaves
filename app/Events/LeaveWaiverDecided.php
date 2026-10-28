<?php

namespace App\Events;

use App\Models\LeaveWaiver;

/** A leave waiver/deferral was approved or rejected. */
class LeaveWaiverDecided
{
    public function __construct(
        public LeaveWaiver $waiver,
        public bool $approved,
    ) {}
}
