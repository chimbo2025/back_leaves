<?php

namespace App\Events;

use App\Models\LeaveRequest;

/** A leave request cleared every step and was finally approved. */
class LeaveRequestApproved
{
    public function __construct(public LeaveRequest $request) {}
}
