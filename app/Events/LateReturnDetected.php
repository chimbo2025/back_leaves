<?php

namespace App\Events;

use App\Models\LeaveReturn;

/** An employee has not reported back past their expected return date. */
class LateReturnDetected
{
    public function __construct(public LeaveReturn $return) {}
}
