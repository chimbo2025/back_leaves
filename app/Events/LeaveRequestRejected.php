<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\LeaveRequest;

/** A leave request was rejected at some step. `comments` may hold the reason. */
class LeaveRequestRejected
{
    public function __construct(
        public LeaveRequest $request,
        public Employee $actor,
        public ?string $comments = null,
    ) {}
}
