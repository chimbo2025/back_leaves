<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\PolicyService;

it('maternity and paternity requests consume the shared annual bank', function () {
    $employee = Employee::find('e3');
    $policy = app(PolicyService::class);

    [$start] = $policy->serviceYearWindow($employee->hire_date);
    $day = $start->copy()->addDays(10)->toDateString();

    $before = (float) $policy->balanceFor($employee->id, 'lt1')['used'];

    LeaveRequest::create([
        'ref' => 'MAT-TEST-1',
        'employee_id' => $employee->id,
        'leave_type_id' => 'lt3',
        'start_date' => $day,
        'end_date' => $day,
        'days' => 5,
        'status' => 'approved',
        'reason' => 'maternity consumption check',
        'is_half_day' => false,
        'is_emergency_exception' => false,
    ]);

    $afterMaternity = (float) $policy->balanceFor($employee->id, 'lt1')['used'];
    expect($afterMaternity)->toBe($before + 5.0);

    LeaveRequest::create([
        'ref' => 'PAT-TEST-1',
        'employee_id' => $employee->id,
        'leave_type_id' => 'lt4',
        'start_date' => $start->copy()->addDays(20)->toDateString(),
        'end_date' => $start->copy()->addDays(20)->toDateString(),
        'days' => 2,
        'status' => 'approved',
        'reason' => 'paternity consumption check',
        'is_half_day' => false,
        'is_emergency_exception' => false,
    ]);

    $afterPaternity = (float) $policy->balanceFor($employee->id, 'lt1')['used'];
    expect($afterPaternity)->toBe($afterMaternity + 2.0);
});
