<?php

namespace App\Events;

use App\Models\LeaveRequest;

/** An approved leave starts within the configured lead time. */
class LeaveStartingSoon
{
    public function __construct(public LeaveRequest $request) {}
}
