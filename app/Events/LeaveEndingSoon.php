<?php

namespace App\Events;

use App\Models\LeaveRequest;

/** An approved leave ends within the configured lead time. */
class LeaveEndingSoon
{
    public function __construct(public LeaveRequest $request) {}
}
