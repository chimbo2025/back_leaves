<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowResolver;
use Illuminate\Support\Facades\DB;

it('resolves workflow scope with precedence unit -> division -> department -> zone -> default', function () {
    // Give e3 a placement that exercises every rung.
    DB::table('employees')->where('id', 'e3')->update([
        'division_id' => 'd2',
        'unit_id' => 'd6',
    ]);
    $employee = Employee::find('e3');

    $request = LeaveRequest::create([
        'ref' => 'SCOPE-TEST',
        'employee_id' => $employee->id,
        'leave_type_id' => 'lt1',
        'start_date' => now()->addDays(30)->toDateString(),
        'end_date' => now()->addDays(34)->toDateString(),
        'days' => 5,
        'status' => 'draft',
        'reason' => 'scope precedence',
    ]);

    // Seeded default definition may exist (wf_annual, no scope). Deactivate it
    // so only our explicit rungs compete, then re-activate at the end? We leave
    // seeded state untouched by using a fresh leave type? lt1 only type with
    // workflow. Instead deactivate seeded wf_annual temporarily.
    DB::table('workflow_definitions')->where('id', 'wf_annual')->update(['is_active' => false]);

    $make = function (string $id, ?string $dept, ?string $zone, int $version) {
        WorkflowDefinition::create([
            'id' => $id,
            'leave_type_id' => 'lt1',
            'version' => $version,
            'min_duration_days' => 0,
            'zone_id' => $zone,
            'department_id' => $dept,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'created_by' => 'u3',
            'approved_by' => 'u1',
            'is_active' => true,
        ]);
    };

    $make('wf_scope_default', null, null, 900);
    $make('wf_scope_zone', null, 'z1', 901);
    $make('wf_scope_dept', 'd1', null, 902);
    $make('wf_scope_division', 'd2', null, 903);
    $make('wf_scope_unit', 'd6', null, 904);

    $resolver = app(WorkflowResolver::class);
    $method = new \ReflectionMethod($resolver, 'findDefinition');
    $method->setAccessible(true);

    $resolve = fn () => $method->invoke($resolver, $employee->fresh(), $request->fresh());

    expect($resolve()->id)->toBe('wf_scope_unit');

    WorkflowDefinition::find('wf_scope_unit')->delete();
    expect($resolve()->id)->toBe('wf_scope_division');

    WorkflowDefinition::find('wf_scope_division')->delete();
    expect($resolve()->id)->toBe('wf_scope_dept');

    WorkflowDefinition::find('wf_scope_dept')->delete();
    expect($resolve()->id)->toBe('wf_scope_zone');

    WorkflowDefinition::find('wf_scope_zone')->delete();
    expect($resolve()->id)->toBe('wf_scope_default');

    // cleanup: remove remaining test def and restore seeded
    WorkflowDefinition::find('wf_scope_default')?->delete();
    DB::table('workflow_definitions')->where('id', 'wf_annual')->update(['is_active' => true]);
    DB::table('employees')->where('id', 'e3')->update(['division_id' => null, 'unit_id' => null]);
});
