<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\LeaveRequest;

/** A leave request entered the approval chain and the first approver was assigned. */
class LeaveRequestSubmitted
{
    public function __construct(
        public LeaveRequest $request,
        public Employee $approver,
    ) {}
}
