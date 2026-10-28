<?php

namespace App\Events;

use App\Models\Employee;

/** An employee's remaining balance for a leave type is at or below threshold. */
class LeaveBalanceReminderDue
{
    /**
     * @param  array<string,mixed>  $balance
     */
    public function __construct(
        public Employee $employee,
        public string $leaveTypeId,
        public array $balance,
    ) {}
}
