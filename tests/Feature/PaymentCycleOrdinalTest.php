<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\PolicyService;
use Illuminate\Support\Facades\DB;

function annualRequestFor(Employee $employee, LeaveType $annual, string $ref, string $start, string $status, string $createdAt): LeaveRequest
{
    $request = LeaveRequest::create([
        'ref' => $ref,
        'employee_id' => $employee->id,
        'leave_type_id' => $annual->id,
        'start_date' => $start,
        'end_date' => $start,
        'days' => 1,
        'status' => $status,
        'reason' => 'Payment cycle check',
        'is_half_day' => false,
        'is_emergency_exception' => false,
    ]);

    DB::table('leave_requests')->where('id', $request->id)->update(['created_at' => $createdAt]);

    return $request->fresh();
}

function isolateAnnualHistory(Employee $employee, LeaveType $annual): void
{
    LeaveRequest::where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->update(['status' => 'withdrawn']);
}

it('makes only every second approved annual request in a service year payment-eligible', function () {
    $annual = LeaveType::where('code', 'ANNUAL')->firstOrFail();
    $employee = Employee::where('id', 'e3')->firstOrFail();
    isolateAnnualHistory($employee, $annual);
    $policy = app(PolicyService::class);

    [$windowStart] = $policy->serviceYearWindow($employee->hire_date);
    $day = fn (int $offset) => $windowStart->copy()->addDays($offset)->toDateString();

    $first = annualRequestFor($employee, $annual, 'PAY-1', $day(5), 'approved', now()->subDays(30)->toDateTimeString());
    $second = annualRequestFor($employee, $annual, 'PAY-2', $day(40), 'approved', now()->subDays(20)->toDateTimeString());
    $third = annualRequestFor($employee, $annual, 'PAY-3', $day(80), 'approved', now()->subDays(10)->toDateTimeString());
    $pending = annualRequestFor($employee, $annual, 'PAY-4', $day(120), 'under_review', now()->subDays(1)->toDateTimeString());

    expect($policy->annualRequestOrdinal($first))->toBe(1);
    expect($policy->annualRequestOrdinal($second))->toBe(2);
    expect($policy->annualRequestOrdinal($third))->toBe(3);
    expect($policy->annualRequestOrdinal($pending))->toBe(4);
});

it('does not count approved annual requests from another service year', function () {
    $annual = LeaveType::where('code', 'ANNUAL')->firstOrFail();
    $employee = Employee::where('id', 'e3')->firstOrFail();
    isolateAnnualHistory($employee, $annual);
    $policy = app(PolicyService::class);

    [$windowStart] = $policy->serviceYearWindow($employee->hire_date);
    $earlierYear = $windowStart->copy()->subYear()->addDays(10)->toDateString();

    annualRequestFor($employee, $annual, 'PAY-OLD', $earlierYear, 'approved', now()->subYears(2)->toDateTimeString());
    $current = annualRequestFor($employee, $annual, 'PAY-NOW', $windowStart->copy()->addDays(5)->toDateString(), 'approved', now()->subDays(5)->toDateTimeString());

    expect($policy->annualRequestOrdinal($current))->toBe(1);
});
