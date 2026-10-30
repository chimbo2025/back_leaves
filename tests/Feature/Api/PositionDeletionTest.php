<?php

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

it('deletes a position and detaches assigned employees without deleting them', function () {
    $admin = apiLogin('u1');
    $employee = Employee::whereNotNull('position_id')->firstOrFail();
    $positionId = $employee->position_id;
    $assignedCount = Employee::where('position_id', $positionId)->count();

    $this->deleteJson("/api/org/positions/{$positionId}", [], ['Authorization' => "Bearer {$admin}"])
        ->assertOk()
        ->assertJsonPath('detached_employees', $assignedCount);

    expect(Position::find($positionId))->toBeNull();
    expect(Employee::find($employee->id))->not->toBeNull();
    expect(Employee::find($employee->id)->position_id)->toBeNull();
    expect(Employee::where('position_id', $positionId)->count())->toBe(0);
    expect(DB::table('position_default_roles')->where('position_id', $positionId)->count())->toBe(0);

    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'position',
        'entity_id' => $positionId,
        'action' => 'delete',
    ]);
});
