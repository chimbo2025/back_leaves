<?php

namespace App\Events;

use App\Models\LeaveRequest;

/** A leave request was returned to the requester for correction. */
class LeaveRequestReturned
{
    public function __construct(
        public LeaveRequest $request,
        public ?string $comments = null,
    ) {}
}
